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
 * Response-shape contracts the marketplace app parses exactly.
 *
 * These pin the fields named in docs/marketplace-backend-asks.md — a rename that passes
 * every behavioural test still breaks the app, so the shape is asserted directly.
 */
class MarketplaceAsksTest extends ApiTestCase
{
    private User $vendor;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');

        $cat = Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
                                 'icon' => '', 'status' => true]);
        $pc  = ProductCategory::create(['name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night']);
        AllowedProductCategory::create(['category_id' => $cat->id, 'product_category_id' => $pc->id]);

        $site = Site::create([
            'name' => 'Sagar Resort', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46, 'phone' => '9876543210', 'whatsapp' => '9876543210',
        ]);
        $site->categories()->attach($cat->id);

        $this->product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $pc->id,
            'name' => 'Sea View Room', 'slug' => 'sea-view-room',
            'base_price' => 2400, 'unit' => 'per_night', 'status' => 'approved',
        ]);
        ProductVariant::create(['product_id' => $this->product->id, 'name' => 'Standard',
                                'price' => 2400, 'is_default' => true, 'status' => true]);
    }

    /** C1 — every field the edit screen prefills. */
    public function test_get_product_returns_all_editable_fields(): void
    {
        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/getProduct', ['id' => $this->product->id])
        );

        foreach (['name', 'description', 'base_price', 'sale_price', 'unit', 'attributes',
                  'product_category_id', 'gallery', 'variants'] as $field) {
            $this->assertArrayHasKey($field, $r->json('data'), "edit prefill needs `{$field}`");
        }
    }

    /** C2 — the analytics screen reads these directly rather than summing the series. */
    public function test_product_analytics_exposes_conversion_rate_and_lead_breakdown(): void
    {
        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/productAnalytics', ['id' => $this->product->id])
        );

        $this->assertArrayHasKey('conversion_rate', $r->json('data'));
        foreach (['call', 'whatsapp', 'directions', 'enquiry'] as $type) {
            $this->assertArrayHasKey($type, $r->json('data.leads_by_type'));
        }
        $this->assertArrayHasKey('name', $r->json('data.product'));
    }

    /** C3 — the gallery is the full ordered array, not just the cover. */
    public function test_product_detail_returns_the_full_ordered_gallery(): void
    {
        foreach ([1, 2] as $i) {
            $this->product->gallery()->create([
                'title' => "img{$i}", 'path' => "demo/p{$i}.png", 'status' => true,
                'sort_order' => $i, 'is_cover' => $i === 1,
            ]);
        }

        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/productDetail', ['id' => $this->product->id])
        );

        $gallery = $r->json('data.gallery');
        $this->assertCount(2, $gallery);
        foreach (['id', 'path', 'path_url', 'is_cover', 'sort_order'] as $f) {
            $this->assertArrayHasKey($f, $gallery[0], "gallery item needs `{$f}`");
        }
        $this->assertSame([1, 2], array_column($gallery, 'sort_order'), 'ordered by sort_order');
    }

    /** C4 — favouriting must not be a dead end. */
    public function test_favourites_list_returns_product_cards_filtered_by_type(): void
    {
        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addDeleteFavourite', [
            'favouritable_id' => $this->product->id, 'favouritable_type' => 'Product',
        ]);

        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/favourites', ['favouritable_type' => 'Product'])
        );

        $row = $r->json('data.data.0.favouritable');
        $this->assertSame('Sea View Room', $row['name']);
        $this->assertArrayHasKey('default_variant', $row, 'card needs a price');
        $this->assertArrayHasKey('product_category', $row);
        $this->assertArrayHasKey('site', $row);
    }

    public function test_favourites_hides_a_listing_that_is_no_longer_live(): void
    {
        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addDeleteFavourite', [
            'favouritable_id' => $this->product->id, 'favouritable_type' => 'Product',
        ]);
        $this->product->update(['status' => 'paused']);

        $r = $this->actingAs($this->vendor, 'api')
            ->postJson('/api/v2/favourites', ['favouritable_type' => 'Product']);

        $this->assertNull($r->json('data.data.0.favouritable'), 'unavailable rather than a broken card');
        $this->assertTrue($r->json('data.data.0.unavailable'));
    }

    /** D — myUsageStats must stay nested; a flat shape produced NaN in the app. */
    public function test_usage_stats_stays_nested(): void
    {
        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myUsageStats')
        );

        $this->assertArrayHasKey('total', $r->json('data.leads'));
        $this->assertArrayHasKey('total', $r->json('data.views'));
        $this->assertArrayHasKey('total', $r->json('data.listings'));
        $this->assertArrayHasKey('conversion_rate', $r->json('data'));
    }

    /** D — public contact + geo on the site, never the owner's details. */
    public function test_product_detail_carries_public_contact_and_geo(): void
    {
        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/productDetail', ['id' => $this->product->id])
        );

        $site = $r->json('data.site');
        foreach (['phone', 'whatsapp', 'latitude', 'longitude'] as $f) {
            $this->assertArrayHasKey($f, $site, "detail screen needs site.{$f}");
        }
        $this->assertSame('9876543210', $site['phone']);
    }
}
