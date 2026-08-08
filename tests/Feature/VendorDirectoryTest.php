<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Services\PlanService;
use Tests\ApiTestCase;

/**
 * Vendor directory — admin and public.
 *
 * One owner may hold several businesses, so both surfaces are vendor-centric rather than
 * site-centric. The public one takes its identity from the vendor's primary business,
 * because users.name / email / mobile are encrypted personal data a tourist must not see.
 */
class VendorDirectoryTest extends ApiTestCase
{
    private User $admin;
    private User $tourist;
    private User $vendor;
    private Category $hotel;
    private ProductCategory $roomNight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin   = $this->userWithRole('admin');
        $this->tourist = $this->userWithRole('user');
        $this->vendor  = $this->userWithRole('vendor');
        app(PlanService::class)->enrolOnFree($this->vendor);

        $this->hotel = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
        ]);

        AllowedProductCategory::create([
            'category_id' => $this->hotel->id, 'product_category_id' => $this->roomNight->id,
        ]);
    }

    private function site(string $name, array $overrides = [], ?User $owner = null): Site
    {
        $site = Site::create(array_merge([
            'name' => $name, 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => ($owner ?? $this->vendor)->id,
            'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ], $overrides));

        $site->categories()->attach($this->hotel->id);

        return $site;
    }

    private function product(Site $site, string $name, string $status = 'approved'): Product
    {
        $p = Product::create([
            'site_id' => $site->id, 'product_category_id' => $this->roomNight->id,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'base_price' => 2400, 'status' => $status,
        ]);

        ProductVariant::create([
            'product_id' => $p->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);

        return $p;
    }

    // ── Admin ────────────────────────────────────────────────────────────────────

    public function test_admin_can_list_vendors_with_their_counts(): void
    {
        $head   = $this->site('Sagar Resort Tarkarli', ['is_primary' => true]);
        $branch = $this->site('Sagar Resort Malvan');
        $this->product($head, 'Deluxe Room');
        $this->product($branch, 'Sea View Room', 'pending');
        $this->product($head, 'Draft Room', 'draft');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/listVendors')
        );

        $row = collect($response->json('data.data'))->firstWhere('id', $this->vendor->id);

        $this->assertNotNull($row);
        $this->assertSame('Sagar Resort Tarkarli', $row['business_name'], 'the primary business names the vendor');
        $this->assertSame(2, $row['sites']['total']);
        $this->assertSame(3, $row['products']['total']);
        $this->assertSame(1, $row['products']['approved']);
        $this->assertSame(1, $row['products']['pending']);
        $this->assertSame('free', $row['plan']['code']);
    }

    public function test_admin_vendor_list_excludes_non_vendors(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/admin/v2/listVendors');
        $ids      = collect($response->json('data.data'))->pluck('id');

        $this->assertNotContains($this->tourist->id, $ids);
        $this->assertNotContains($this->admin->id, $ids);
    }

    public function test_admin_can_filter_to_vendors_with_products_awaiting_review(): void
    {
        $quiet = $this->userWithRole('vendor');
        $this->site('Quiet Business', [], $quiet);

        $busy = $this->site('Busy Business');
        $this->product($busy, 'Needs Review', 'pending');

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/admin/v2/listVendors', ['has_pending_products' => true]);

        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertContains($this->vendor->id, $ids);
        $this->assertNotContains($quiet->id, $ids);
    }

    public function test_admin_vendor_detail_carries_sites_categories_products_and_plan(): void
    {
        $head = $this->site('Sagar Resort', ['is_primary' => true]);
        $this->product($head, 'Deluxe Room');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/getVendor', ['id' => $this->vendor->id])
        );

        $this->assertSame($this->vendor->id, $response->json('data.vendor.id'));
        $this->assertCount(1, $response->json('data.sites'));
        $this->assertSame('Hotel Rooms', $response->json('data.sites.0.categories.0.name'));
        $this->assertSame(1, $response->json('data.products.by_status.approved'));
        $this->assertSame('free', $response->json('data.plan.code'));
        $this->assertArrayHasKey('max_products', $response->json('data.usage'));
    }

    public function test_admin_vendor_detail_refuses_a_non_vendor(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/getVendor', ['id' => $this->tourist->id]),
            422
        );
    }

    public function test_the_admin_vendor_directory_is_closed_to_vendors(): void
    {
        foreach (['listVendors', 'getVendor'] as $endpoint) {
            $this->actingAs($this->vendor, 'api')
                ->postJson("/admin/v2/{$endpoint}", ['id' => $this->vendor->id])
                ->assertStatus(403);
        }
    }

    // ── Public ───────────────────────────────────────────────────────────────────

    public function test_a_vendor_profile_groups_every_business_under_one_owner(): void
    {
        $head   = $this->site('Sagar Resort Tarkarli', ['is_primary' => true]);
        $branch = $this->site('Sagar Resort Malvan');
        $this->product($head, 'Deluxe Room');
        $this->product($branch, 'Garden Room');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/vendorProfile', ['id' => $this->vendor->id])
        );

        $this->assertSame('Sagar Resort Tarkarli', $response->json('data.vendor.business_name'));
        $this->assertSame(2, $response->json('data.vendor.outlet_count'));
        $this->assertSame(2, $response->json('data.vendor.product_count'));
        $this->assertCount(2, $response->json('data.outlets'));
        $this->assertCount(2, $response->json('data.products.data'), 'catalog spans both outlets');
    }

    public function test_a_vendor_profile_never_exposes_the_owners_personal_details(): void
    {
        $this->site('Sagar Resort', ['is_primary' => true]);

        $body = $this->actingAs($this->tourist, 'api')
            ->postJson('/api/v2/vendorProfile', ['id' => $this->vendor->id])
            ->getContent();

        $this->assertStringNotContainsString($this->vendor->email, $body);
        $this->assertStringNotContainsString($this->vendor->name, $body);
        $this->assertStringNotContainsString('"mobile"', $body);
    }

    public function test_a_vendor_profile_shows_only_live_businesses_and_products(): void
    {
        $live = $this->site('Live Business', ['is_primary' => true]);
        $this->site('Pending Business', ['status' => false, 'submission_status' => 'pending']);
        $this->product($live, 'Visible Room');
        $this->product($live, 'Draft Room', 'draft');

        $response = $this->actingAs($this->tourist, 'api')
            ->postJson('/api/v2/vendorProfile', ['id' => $this->vendor->id]);

        $this->assertCount(1, $response->json('data.outlets'));
        $names = collect($response->json('data.products.data'))->pluck('name');
        $this->assertSame(['Visible Room'], $names->all());
    }

    public function test_a_vendor_with_no_live_business_has_no_public_profile(): void
    {
        $this->site('Still Pending', ['status' => false, 'submission_status' => 'pending']);

        $this->assertApiFailure(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/vendorProfile', ['id' => $this->vendor->id]),
            404
        );
    }

    public function test_a_non_vendor_has_no_public_profile(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/vendorProfile', ['id' => $this->admin->id]),
            404
        );
    }

    public function test_the_public_vendor_list_shows_one_card_per_owner(): void
    {
        $this->site('Sagar Resort Tarkarli', ['is_primary' => true]);
        $this->site('Sagar Resort Malvan');

        $other = $this->userWithRole('vendor');
        $this->site('Malvani Katta', ['is_primary' => true], $other);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/listVendors')
        );

        $rows = collect($response->json('data.data'));

        $this->assertCount(2, $rows, 'two owners, not three businesses');

        $sagar = $rows->firstWhere('id', $this->vendor->id);
        $this->assertSame('Sagar Resort Tarkarli', $sagar['business_name']);
        $this->assertSame(2, $sagar['outlet_count']);
    }

    public function test_the_public_vendor_list_can_be_filtered_by_category(): void
    {
        $this->site('Sagar Resort', ['is_primary' => true]);

        $restaurantCat = Category::create([
            'name' => 'Restaurant', 'mr_name' => 'हॉटेल', 'code' => 'restaurant',
            'icon' => '', 'status' => true,
        ]);
        $other    = $this->userWithRole('vendor');
        $eatery   = Site::create([
            'name' => 'Malvani Katta', 'description' => 'A Kokan eatery used for testing purposes.',
            'user_id' => $other->id, 'status' => true, 'submission_status' => 'approved',
            'is_primary' => true, 'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $eatery->categories()->attach($restaurantCat->id);

        $response = $this->actingAs($this->tourist, 'api')
            ->postJson('/api/v2/listVendors', ['category_code' => 'restaurant']);

        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertSame([$other->id], $ids->all());
    }

    public function test_a_vendor_whose_only_business_is_pending_is_not_listed(): void
    {
        $this->site('Still Pending', ['status' => false, 'submission_status' => 'pending']);

        $response = $this->actingAs($this->tourist, 'api')->postJson('/api/v2/listVendors');

        $this->assertNotContains($this->vendor->id, collect($response->json('data.data'))->pluck('id'));
    }

    public function test_the_public_vendor_list_reports_distance_to_the_nearest_outlet(): void
    {
        $this->site('Far Outlet', ['is_primary' => true, 'latitude' => 16.99, 'longitude' => 73.31]);
        $this->site('Near Outlet', ['latitude' => 16.0512, 'longitude' => 73.4680]);

        $response = $this->actingAs($this->tourist, 'api')->postJson('/api/v2/listVendors', [
            'latitude' => 16.0512, 'longitude' => 73.4680,
        ]);

        $row = collect($response->json('data.data'))->firstWhere('id', $this->vendor->id);

        $this->assertLessThan(1, $row['distance_km'], 'measured to the closest outlet, not the primary');
    }
}
