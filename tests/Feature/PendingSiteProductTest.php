<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * A vendor builds their catalog while the business is still under review, so onboarding is
 * one round trip instead of two. Nothing becomes publicly visible early.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §2.6.
 */
class PendingSiteProductTest extends ApiTestCase
{
    private User $vendor;
    private User $admin;
    private Site $pendingSite;
    private ProductCategory $roomNight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');
        $this->admin  = $this->userWithRole('admin');

        $hotel = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);

        AllowedProductCategory::create([
            'category_id' => $hotel->id, 'product_category_id' => $this->roomNight->id,
        ]);

        $this->pendingSite = $this->site(['submission_status' => 'pending', 'status' => false]);
        $this->pendingSite->categories()->attach($hotel->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'name'              => 'Outlet ' . uniqid(),
            'description'       => 'A Kokan business listing used for testing purposes.',
            'user_id'           => $this->vendor->id,
            'latitude'          => 16.0,
            'longitude'         => 73.4,
        ], $overrides));
    }

    private function addProductTo(Site $site, array $overrides = [])
    {
        return $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addProduct', array_merge([
            'site_id'             => $site->id,
            'product_category_id' => $this->roomNight->id,
            'name'                => 'Deluxe Room ' . uniqid(),
            'base_price'          => 2400,
            'unit'                => 'per_night',
        ], $overrides));
    }

    // ── Building the catalog early ───────────────────────────────────────────────

    public function test_a_vendor_can_add_products_while_the_business_is_still_pending(): void
    {
        $this->assertApiSuccess($this->addProductTo($this->pendingSite));

        $this->assertDatabaseHas('products', [
            'site_id' => $this->pendingSite->id,
            'status'  => 'draft',
        ]);
    }

    public function test_products_on_a_pending_site_can_be_submitted_for_review(): void
    {
        $this->addProductTo($this->pendingSite);
        $product = Product::first();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/submitProductForReview', ['id' => $product->id])
        );

        $this->assertSame('pending', $product->fresh()->status, 'both queues fill in parallel');
    }

    public function test_a_rejected_site_cannot_receive_new_products(): void
    {
        $rejected = $this->site(['submission_status' => 'rejected', 'status' => false]);

        $this->assertApiFailure($this->addProductTo($rejected), 403);
    }

    public function test_the_category_picker_works_for_a_pending_site(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $this->pendingSite->id])
        );

        $this->assertSame(['room_night'], collect($response->json('data'))->pluck('code')->all());
    }

    public function test_my_sites_includes_pending_businesses_so_the_app_can_offer_them(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/mySites')
        );

        $rows = collect($response->json('data.data'));

        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows->first()['submission_status'], 'the app can badge it as under review');
    }

    public function test_my_sites_excludes_rejected_businesses(): void
    {
        $this->site(['submission_status' => 'rejected', 'status' => false]);

        $response = $this->actingAs($this->vendor, 'api')->postJson('/api/v2/mySites');

        $this->assertCount(1, collect($response->json('data.data')));
    }

    // ── Nothing leaks out early ──────────────────────────────────────────────────

    public function test_a_product_cannot_be_approved_while_its_site_is_still_pending(): void
    {
        $this->addProductTo($this->pendingSite);
        $product = Product::first();
        $product->update(['status' => 'pending']);

        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/approveProduct', ['id' => $product->id]),
            422
        );

        $this->assertSame('pending', $product->fresh()->status, 'the site must be approved first');
    }

    public function test_an_approved_product_is_not_live_while_its_site_is_not(): void
    {
        // The product row can legitimately be `approved` and the site later unpublished;
        // the listing must disappear with it.
        $this->addProductTo($this->pendingSite);
        Product::first()->update(['status' => 'approved']);

        $this->assertSame(0, Product::live()->count(), 'no site, no listing');

        $this->pendingSite->update(['submission_status' => 'approved', 'status' => true]);

        $this->assertSame(1, Product::live()->count());
    }

    public function test_unpublishing_a_site_hides_its_live_products(): void
    {
        $approvedSite = $this->site(['submission_status' => 'approved', 'status' => true]);
        $approvedSite->categories()->attach(Category::where('code', 'hotel_rooms')->first()->id);

        $this->addProductTo($approvedSite);
        Product::first()->update(['status' => 'approved']);

        $this->assertSame(1, Product::live()->count());

        $approvedSite->update(['status' => false]);

        $this->assertSame(0, Product::live()->count());
    }

    // ── The whole flow ───────────────────────────────────────────────────────────

    public function test_one_review_round_takes_the_business_and_its_catalog_live(): void
    {
        foreach (['Deluxe Room', 'Standard Room', 'Sea View Suite'] as $name) {
            $this->addProductTo($this->pendingSite, ['name' => $name]);
        }

        Product::query()->update(['status' => 'pending']);

        $this->assertSame(0, Product::live()->count());

        // Admin approves the business, then its three listings in the same sitting.
        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/approveSite', ['id' => $this->pendingSite->id])
        );

        foreach (Product::pluck('id') as $id) {
            $this->assertApiSuccess(
                $this->actingAs($this->admin, 'api')->postJson('/admin/v2/approveProduct', ['id' => $id])
            );
        }

        $this->assertSame(3, Product::live()->count(), 'business and catalog go live together');
    }
}
