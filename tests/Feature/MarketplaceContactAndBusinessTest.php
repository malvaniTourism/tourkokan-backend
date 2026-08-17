<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Marketplace asks #2 (public phone/whatsapp on the business) and #4 (vendor-registrable
 * category flag). See docs/marketplace-backend-asks.md and docs/marketplace-backend-response.md.
 */
class MarketplaceContactAndBusinessTest extends ApiTestCase
{
    private User $vendor;
    private Category $hotelRooms;
    private Category $emergency;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');

        // A registrable branch (has a whitelist) and a directory-only one (has none).
        $accommodation = Category::create(['name' => 'Accommodation', 'mr_name' => 'निवास', 'code' => 'accomodation', 'icon' => '', 'status' => true, 'is_business' => true]);
        $this->hotelRooms = Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms', 'parent_id' => $accommodation->id, 'icon' => '', 'status' => true, 'is_business' => true]);
        $this->emergency = Category::create(['name' => 'Emergency', 'mr_name' => 'आपत्कालीन', 'code' => 'emergency', 'icon' => '', 'status' => true, 'is_business' => false]);
        Category::create(['name' => 'Hospital', 'mr_name' => 'रुग्णालय', 'code' => 'hospital', 'parent_id' => $this->emergency->id, 'icon' => '', 'status' => true, 'is_business' => false]);

        $pc = ProductCategory::create(['name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night', 'booking_type' => 'date_range']);
        AllowedProductCategory::create(['category_id' => $this->hotelRooms->id, 'product_category_id' => $pc->id]);

        $site = Site::create([
            'name' => 'Sagar Resort', 'description' => 'A sea-facing resort used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
            'phone' => '+91 9876543210', 'whatsapp' => '9876543210',
        ]);
        $site->categories()->attach($this->hotelRooms->id);

        $this->product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $pc->id,
            'name' => 'Deluxe Room', 'slug' => 'deluxe-room', 'base_price' => 2400, 'status' => 'approved',
        ]);
        ProductVariant::create(['product_id' => $this->product->id, 'name' => 'Standard', 'price' => 2400, 'is_default' => true, 'status' => true]);
    }

    // ── #2 public contact + geo ──────────────────────────────────────────────────

    public function test_product_detail_exposes_business_phone_whatsapp_and_geo(): void
    {
        $tourist = $this->userWithRole('user');

        $site = $this->assertApiSuccess(
            $this->actingAs($tourist, 'api')->postJson('/api/v2/productDetail', ['id' => $this->product->id])
        )->json('data.site');

        $this->assertSame('+91 9876543210', $site['phone']);
        $this->assertSame('9876543210', $site['whatsapp']);
        $this->assertEquals(16.05, $site['latitude']);
        $this->assertEquals(73.46, $site['longitude']);
    }

    public function test_list_products_row_carries_phone_and_whatsapp(): void
    {
        $tourist = $this->userWithRole('user');

        $row = $this->actingAs($tourist, 'api')->postJson('/api/v2/listProducts')->json('data.data.0');

        $this->assertArrayHasKey('phone', $row['site']);
        $this->assertArrayHasKey('whatsapp', $row['site']);
    }

    public function test_owner_pii_is_never_exposed_as_the_business_contact(): void
    {
        // The public phone is the site's, not the encrypted owner record.
        $tourist = $this->userWithRole('user');
        $site = $this->actingAs($tourist, 'api')
            ->postJson('/api/v2/productDetail', ['id' => $this->product->id])->json('data.site');

        $this->assertArrayNotHasKey('user', $site);
        $this->assertArrayNotHasKey('email', $site);
    }

    public function test_a_vendor_can_save_a_phone_when_submitting_a_business(): void
    {
        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addSite', [
                'name' => 'New Outlet', 'description' => 'A brand new outlet for the marketplace tests here.',
                'categories' => [$this->hotelRooms->id], 'latitude' => 16.1, 'longitude' => 73.5,
                'phone' => '02362 111222', 'whatsapp' => '9800000000',
            ])
        );

        $this->assertDatabaseHas('sites', ['name' => 'New Outlet', 'phone' => '02362 111222', 'whatsapp' => '9800000000']);
    }

    public function test_a_non_numeric_phone_is_rejected(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addSite', [
                'name' => 'Bad Phone', 'description' => 'A business whose phone is not a phone number here.',
                'categories' => [$this->hotelRooms->id], 'latitude' => 16.1, 'longitude' => 73.5,
                'phone' => 'call-me-maybe',
            ]),
            422
        );
    }

    // ── #4 vendor-registrable categories ─────────────────────────────────────────

    public function test_business_categories_returns_only_registrable_branches(): void
    {
        $tourist = $this->userWithRole('user');

        $names = collect(
            $this->assertApiSuccess($this->actingAs($tourist, 'api')->postJson('/api/v2/businessCategories'))->json('data')
        )->pluck('name');

        $this->assertContains('Accommodation', $names);
        $this->assertNotContains('Emergency', $names, 'directory-only branches are hidden from the picker');
    }

    public function test_business_categories_includes_children_and_the_flag(): void
    {
        $tourist = $this->userWithRole('user');

        $accommodation = collect(
            $this->actingAs($tourist, 'api')->postJson('/api/v2/businessCategories')->json('data')
        )->firstWhere('code', 'accomodation');

        $this->assertTrue($accommodation['is_business']);
        $this->assertContains('hotel_rooms', collect($accommodation['sub_categories'])->pluck('code'));
    }
}
