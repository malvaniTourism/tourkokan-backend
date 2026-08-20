<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLead;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Buyer-side enquiry history (C5) — the mirror of the vendor's myLeads. A buyer sees the
 * listings they reached out about, newest first, and only their own; a listing that has
 * since gone away is flagged unavailable rather than dropped.
 */
class BuyerEnquiriesTest extends ApiTestCase
{
    private User $buyer;
    private User $otherBuyer;
    private Site $site;
    private ProductCategory $roomNight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer      = $this->userWithRole('user');
        $this->otherBuyer = $this->userWithRole('user');
        $vendor           = $this->userWithRole('vendor');

        $category = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);

        $this->site = Site::create([
            'name' => 'Tarkarli Resort', 'description' => 'Test business.',
            'user_id' => $vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $this->site->categories()->attach($category->id);
    }

    private function makeProduct(string $status = 'approved'): Product
    {
        $product = Product::create([
            'site_id' => $this->site->id, 'product_category_id' => $this->roomNight->id,
            'name' => 'Room ' . uniqid(), 'slug' => 'room-' . uniqid(),
            'base_price' => 2400, 'status' => $status,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);

        return $product;
    }

    private function lead(User $user, Product $product, string $type = 'call'): ProductLead
    {
        return ProductLead::create([
            'product_id' => $product->id, 'user_id' => $user->id, 'lead_type' => $type,
            'message' => 'Is it available this weekend?',
        ]);
    }

    public function test_returns_only_the_callers_own_enquiries_newest_first(): void
    {
        $mine  = $this->makeProduct();
        $other = $this->makeProduct();

        $this->lead($this->buyer, $mine, 'call');
        $this->lead($this->buyer, $mine, 'whatsapp');
        $this->lead($this->otherBuyer, $other, 'call');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->buyer, 'api')->postJson('/api/v2/myEnquiries')
        );

        $rows = $response->json('data.data');

        $this->assertCount(2, $rows, 'Only the buyer\'s own two enquiries.');
        $this->assertSame('whatsapp', $rows[0]['lead_type'], 'Newest first.');
        $this->assertSame($mine->id, $rows[0]['product']['id']);
        $this->assertTrue($rows[0]['available']);
        // Vendor-side lead state must not leak to the buyer.
        $this->assertArrayNotHasKey('is_read', $rows[0]);
        $this->assertArrayNotHasKey('ip_hash', $rows[0]);
    }

    public function test_lead_type_filter_narrows_the_history(): void
    {
        $product = $this->makeProduct();
        $this->lead($this->buyer, $product, 'call');
        $this->lead($this->buyer, $product, 'whatsapp');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->buyer, 'api')
                ->postJson('/api/v2/myEnquiries', ['lead_type' => 'whatsapp'])
        );

        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('whatsapp', $rows[0]['lead_type']);
    }

    public function test_a_paused_listing_stays_in_history_flagged_unavailable(): void
    {
        $product = $this->makeProduct('approved');
        $this->lead($this->buyer, $product, 'call');

        $product->update(['status' => 'paused']);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->buyer, 'api')->postJson('/api/v2/myEnquiries')
        );

        $rows = $response->json('data.data');
        $this->assertCount(1, $rows, 'History is not filtered by current availability.');
        $this->assertFalse($rows[0]['available']);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v2/myEnquiries')->assertStatus(401);
    }
}
