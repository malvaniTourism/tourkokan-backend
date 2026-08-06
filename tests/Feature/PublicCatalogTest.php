<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLead;
use App\Models\ProductVariant;
use App\Models\ProductViewEvent;
use App\Models\Rating;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * What tourists see. Every read goes through Product::scopeLive, so the visibility tests
 * here are the ones that matter most — a leak means an unapproved listing reaches the public.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §7.
 */
class PublicCatalogTest extends ApiTestCase
{
    private User $tourist;
    private User $vendor;
    private Site $site;
    private ProductCategory $roomNight;
    private ProductCategory $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tourist = $this->userWithRole('user');
        $this->vendor  = $this->userWithRole('vendor');

        $hotel = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);
        $this->menuItem = ProductCategory::create([
            'name' => 'Menu Item', 'code' => 'menu_item', 'slug' => 'menu-item',
            'booking_type' => 'none',
        ]);

        AllowedProductCategory::create([
            'category_id' => $hotel->id, 'product_category_id' => $this->roomNight->id,
        ]);

        $this->site = $this->makeSite('Tarkarli Resort', 16.0512, 73.4680);
    }

    private function makeSite(string $name, float $lat, float $lng, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'name'              => $name,
            'description'       => 'A Kokan business listing used for testing purposes.',
            'user_id'           => $this->vendor->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ], $overrides));
    }

    private function makeProduct(array $overrides = [], ?float $price = 2400): Product
    {
        $product = Product::create(array_merge([
            'site_id'             => $this->site->id,
            'product_category_id' => $this->roomNight->id,
            'name'                => 'Room ' . uniqid(),
            'slug'                => 'room-' . uniqid(),
            'base_price'          => $price,
            'status'              => 'approved',
        ], $overrides));

        ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Standard',
            'price' => $price ?? 0, 'is_default' => true, 'status' => true,
        ]);

        return $product;
    }

    /**
     * Asserts a successful envelope by default — this API answers 200 on failure, so an
     * unasserted validation error is indistinguishable from an empty result set (§0.4).
     */
    private function list(array $payload = [], bool $expectSuccess = true)
    {
        $response = $this->actingAs($this->tourist, 'api')->postJson('/api/v2/listProducts', $payload);

        if ($expectSuccess) {
            $this->assertApiSuccess($response);
        }

        return $response;
    }

    // ── Visibility ───────────────────────────────────────────────────────────────

    public function test_only_live_listings_are_returned(): void
    {
        $this->makeProduct(['name' => 'Visible']);
        $this->makeProduct(['name' => 'Draft', 'status' => 'draft']);
        $this->makeProduct(['name' => 'Pending', 'status' => 'pending']);
        $this->makeProduct(['name' => 'Rejected', 'status' => 'rejected']);
        $this->makeProduct(['name' => 'Paused', 'status' => 'paused']);

        $names = collect($this->assertApiSuccess($this->list())->json('data.data'))->pluck('name');

        $this->assertSame(['Visible'], $names->all());
    }

    public function test_listings_disappear_when_their_site_is_unpublished(): void
    {
        $this->makeProduct();

        $this->assertCount(1, $this->list()->json('data.data'));

        $this->site->update(['status' => false]);

        $this->assertCount(0, $this->list()->json('data.data'), 'no site, no listing');
    }

    public function test_listings_outside_their_availability_window_are_hidden(): void
    {
        $this->makeProduct(['name' => 'Expired', 'available_to' => now()->subDay()->toDateString()]);
        $this->makeProduct(['name' => 'Not yet', 'available_from' => now()->addWeek()->toDateString()]);
        $this->makeProduct(['name' => 'Open']);

        $names = collect($this->list()->json('data.data'))->pluck('name');

        $this->assertSame(['Open'], $names->all());
    }

    public function test_an_unapproved_listing_cannot_be_fetched_by_id(): void
    {
        $draft = $this->makeProduct(['status' => 'draft']);

        $this->assertApiFailure(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/productDetail', ['id' => $draft->id]),
            404
        );
    }

    public function test_browsing_requires_authentication(): void
    {
        $this->postJson('/api/v2/listProducts')->assertStatus(401);
    }

    // ── Filters ──────────────────────────────────────────────────────────────────

    public function test_filtering_by_product_category(): void
    {
        $this->makeProduct(['name' => 'A Room']);
        $this->makeProduct(['name' => 'A Dish', 'product_category_id' => $this->menuItem->id]);

        $names = collect($this->list(['category_code' => 'menu_item'])->json('data.data'))->pluck('name');

        $this->assertSame(['A Dish'], $names->all());
    }

    public function test_filtering_by_price_reads_the_variant_not_the_product(): void
    {
        // R1 — the authoritative price is on the variant. A product whose base_price
        // disagrees must still filter by what a customer would actually pay.
        $cheap = $this->makeProduct(['name' => 'Cheap'], 500);
        $this->makeProduct(['name' => 'Pricey'], 5000);

        $cheap->update(['base_price' => 9999]);              // stale headline figure
        $cheap->defaultVariant->update(['price' => 500]);    // real price

        $names = collect($this->list(['max_price' => 1000])->json('data.data'))->pluck('name');

        $this->assertSame(['Cheap'], $names->all());
    }

    public function test_a_sale_price_is_what_the_price_filter_uses(): void
    {
        $product = $this->makeProduct(['name' => 'Discounted'], 5000);
        $product->defaultVariant->update(['sale_price' => 900]);

        $names = collect($this->list(['max_price' => 1000])->json('data.data'))->pluck('name');

        $this->assertSame(['Discounted'], $names->all());
    }

    public function test_searching_by_name(): void
    {
        $this->makeProduct(['name' => 'Sea View Suite']);
        $this->makeProduct(['name' => 'Garden Cottage']);

        $names = collect($this->list(['search' => 'Sea'])->json('data.data'))->pluck('name');

        $this->assertSame(['Sea View Suite'], $names->all());
    }

    public function test_featured_products_endpoint_returns_only_featured_live_listings(): void
    {
        $this->makeProduct(['name' => 'Plain']);
        $this->makeProduct(['name' => 'Starred', 'is_featured' => true]);
        $this->makeProduct(['name' => 'Starred draft', 'is_featured' => true, 'status' => 'draft']);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/featuredProducts')
        );

        $this->assertSame(['Starred'], collect($response->json('data.data'))->pluck('name')->all());
    }

    // ── Geo ──────────────────────────────────────────────────────────────────────

    public function test_distance_is_returned_and_the_radius_filter_applies(): void
    {
        // Malvan is roughly 10 km from Tarkarli; Ratnagiri is far up the coast.
        $this->makeProduct(['name' => 'At Tarkarli']);

        $malvan = $this->makeSite('Malvan Stay', 16.0590, 73.4700);
        $far    = $this->makeSite('Ratnagiri Stay', 16.9902, 73.3120);

        $this->makeProduct(['name' => 'At Malvan', 'site_id' => $malvan->id]);
        $this->makeProduct(['name' => 'At Ratnagiri', 'site_id' => $far->id]);

        $response = $this->assertApiSuccess($this->list([
            'latitude' => 16.0512, 'longitude' => 73.4680, 'radius_km' => 25, 'sort' => 'nearest',
        ]));

        $rows = collect($response->json('data.data'));

        $this->assertSame(['At Tarkarli', 'At Malvan'], $rows->pluck('name')->all(), 'nearest first, far one excluded');
        $this->assertLessThan(1, (float) $rows->first()['distance_km'], 'the local one is ~0 km away');
    }

    public function test_a_location_without_a_radius_still_returns_distances(): void
    {
        $this->makeProduct(['name' => 'Anywhere']);

        $rows = collect($this->list(['latitude' => 16.0512, 'longitude' => 73.4680])->json('data.data'));

        $this->assertArrayHasKey('distance_km', $rows->first());
    }

    public function test_a_latitude_without_a_longitude_is_rejected(): void
    {
        $this->assertApiFailure($this->list(['latitude' => 16.05], false), 422);
    }

    public function test_a_max_price_without_a_min_price_is_a_valid_filter(): void
    {
        // The commonest filter in any catalogue. An unconditional `gte:min_price` rule
        // rejects it, because the compared field is absent.
        $this->makeProduct(['name' => 'Cheap'], 500);
        $this->makeProduct(['name' => 'Pricey'], 9000);

        $names = collect($this->list(['max_price' => 1000])->json('data.data'))->pluck('name');

        $this->assertSame(['Cheap'], $names->all());
    }

    public function test_a_max_price_below_the_min_price_is_still_rejected(): void
    {
        $this->assertApiFailure($this->list(['min_price' => 900, 'max_price' => 100], false), 422);
    }

    // ── Sorting ──────────────────────────────────────────────────────────────────

    public function test_sorting_by_price_ascending_and_descending(): void
    {
        $this->makeProduct(['name' => 'Mid'], 2000);
        $this->makeProduct(['name' => 'Low'], 500);
        $this->makeProduct(['name' => 'High'], 9000);

        $asc = collect($this->list(['sort' => 'price_asc'])->json('data.data'))->pluck('name');
        $this->assertSame(['Low', 'Mid', 'High'], $asc->all());

        $desc = collect($this->list(['sort' => 'price_desc'])->json('data.data'))->pluck('name');
        $this->assertSame(['High', 'Mid', 'Low'], $desc->all());
    }

    public function test_sorting_by_popularity_uses_leads_before_views(): void
    {
        $a = $this->makeProduct(['name' => 'Many views']);
        $b = $this->makeProduct(['name' => 'Many leads']);

        // views_count / leads_count are deliberately absent from $fillable — they move
        // only through increment(), so update() here would silently do nothing.
        $a->forceFill(['views_count' => 900, 'leads_count' => 1])->save();
        $b->forceFill(['views_count' => 10, 'leads_count' => 20])->save();

        $names = collect($this->list(['sort' => 'popular'])->json('data.data'))->pluck('name');

        $this->assertSame(['Many leads', 'Many views'], $names->all(), 'leads outrank views');
    }

    // ── Detail ───────────────────────────────────────────────────────────────────

    public function test_product_detail_carries_what_a_listing_page_needs(): void
    {
        $product = $this->makeProduct(['name' => 'Sea View Suite']);

        Rating::create([
            'user_id' => $this->tourist->id, 'rate' => 4,
            'rateable_type' => Product::class, 'rateable_id' => $product->id, 'status' => true,
        ]);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/productDetail', ['id' => $product->id])
        );

        $this->assertSame('Sea View Suite', $response->json('data.name'));
        $this->assertSame('2400.00', $response->json('data.price'), 'resolved through the variant');
        $this->assertSame('Tarkarli Resort', $response->json('data.site.name'));
        $this->assertSame('room_night', $response->json('data.product_category.code'));
        $this->assertNotEmpty($response->json('data.variants'));
        $this->assertEquals(4, $response->json('data.ratings_avg_rate'));
        $this->assertFalse($response->json('data.is_favourite'));
    }

    public function test_a_product_can_be_fetched_by_slug(): void
    {
        $product = $this->makeProduct(['slug' => 'sea-view-suite']);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/productDetail', ['slug' => 'sea-view-suite'])
        );

        $this->assertSame($product->id, $response->json('data.id'));
    }

    public function test_products_by_site_returns_that_businesss_catalog_only(): void
    {
        $this->makeProduct(['name' => 'Ours']);

        $other = $this->makeSite('Another Resort', 16.1, 73.5);
        $this->makeProduct(['name' => 'Theirs', 'site_id' => $other->id]);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/productsBySite', ['site_id' => $this->site->id])
        );

        $this->assertSame(['Ours'], collect($response->json('data.data'))->pluck('name')->all());
    }

    // ── Engagement ───────────────────────────────────────────────────────────────

    public function test_recording_a_view_logs_it_and_bumps_the_counter(): void
    {
        $product = $this->makeProduct();

        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/recordProductView', ['id' => $product->id, 'platform' => 'android'])
        );

        $this->assertSame(1, ProductViewEvent::count());
        $this->assertSame(1, $product->fresh()->views_count);
    }

    public function test_recording_a_lead_logs_the_type_and_bumps_the_counter(): void
    {
        $product = $this->makeProduct();

        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
                'id' => $product->id, 'lead_type' => 'whatsapp',
            ])
        );

        $this->assertSame('whatsapp', ProductLead::first()->lead_type);
        $this->assertSame(1, $product->fresh()->leads_count);
    }

    public function test_an_unknown_lead_type_is_rejected(): void
    {
        $product = $this->makeProduct();

        $this->assertApiFailure(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
                'id' => $product->id, 'lead_type' => 'telepathy',
            ]),
            422
        );
    }

    public function test_engagement_cannot_be_recorded_against_a_listing_that_is_not_live(): void
    {
        $draft = $this->makeProduct(['status' => 'draft']);

        foreach (['recordProductView', 'recordProductLead'] as $endpoint) {
            $this->assertApiFailure(
                $this->actingAs($this->tourist, 'api')->postJson("/api/v2/{$endpoint}", [
                    'id' => $draft->id, 'lead_type' => 'call',
                ]),
                404
            );
        }

        $this->assertSame(0, ProductViewEvent::count());
        $this->assertSame(0, ProductLead::count());
    }

    public function test_ip_addresses_are_stored_hashed_not_raw(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->tourist, 'api')
            ->postJson('/api/v2/recordProductView', ['id' => $product->id]);

        $stored = ProductViewEvent::first();

        $this->assertNotNull($stored->getAttributes()['ip_hash']);
        $this->assertSame(64, strlen($stored->getAttributes()['ip_hash']), 'sha256 hex');
        $this->assertStringNotContainsString('127.0.0.1', json_encode($stored->getAttributes()));
    }
}
