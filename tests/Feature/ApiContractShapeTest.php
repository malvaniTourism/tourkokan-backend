<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Pins the wire format documented in docs/vendor-products-api.md.
 *
 * The app is built against those field names; a rename that passes every other test still
 * breaks the client, so the contract is asserted explicitly here.
 */
class ApiContractShapeTest extends ApiTestCase
{
    private User $tourist;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tourist = $this->userWithRole('user');
        $vendor        = $this->userWithRole('vendor');

        $siteCategory = Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms', 'icon' => '', 'status' => true]);
        $pc = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);
        \App\Models\AllowedProductCategory::create([
            'category_id' => $siteCategory->id, 'product_category_id' => $pc->id,
        ]);

        $site = Site::create([
            'name' => 'Sagar Resort', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $site->categories()->attach($siteCategory->id);

        $this->product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $pc->id,
            'name' => 'Deluxe Sea View Room', 'slug' => 'deluxe-sea-view-room',
            'base_price' => 2400, 'unit' => 'per_night', 'status' => 'approved',
        ]);

        ProductVariant::create([
            'product_id' => $this->product->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);
    }

    public function test_list_row_carries_every_documented_field(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/listProducts', [
                'latitude' => 16.05, 'longitude' => 73.46,
            ])
        );

        $row = $response->json('data.data.0');

        foreach ([
            'id', 'name', 'slug', 'base_price', 'currency', 'unit', 'is_featured',
            'fulfilment_type', 'views_count', 'leads_count',
            'rating_avg_rate', 'rating_count', 'distance_km',
            'product_category', 'site', 'default_variant', 'cover',
        ] as $field) {
            $this->assertArrayHasKey($field, $row, "documented list field `{$field}` is missing");
        }

        $this->assertArrayHasKey('code', $row['product_category']);
        $this->assertArrayHasKey('booking_type', $row['product_category']);
        $this->assertArrayHasKey('price', $row['default_variant']);
        $this->assertArrayHasKey('logo', $row['site']);
    }

    public function test_the_envelope_matches_the_documented_shape(): void
    {
        $response = $this->actingAs($this->tourist, 'api')->postJson('/api/v2/listProducts');

        foreach (['version', 'language', 'success', 'message', 'data'] as $key) {
            $response->assertJsonStructure([$key]);
        }

        // rows live at data.data — the paginator sits inside the envelope
        $response->assertJsonStructure(['data' => ['current_page', 'data', 'last_page', 'total']]);
    }

    public function test_detail_carries_every_documented_field(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/productDetail', ['id' => $this->product->id])
        );

        foreach ([
            'id', 'name', 'slug', 'price', 'is_favourite',
            'rating_avg_rate', 'rating_count', 'comment_count',
            'variants', 'gallery', 'product_category', 'site',
        ] as $field) {
            $this->assertArrayHasKey($field, $response->json('data'), "documented detail field `{$field}` is missing");
        }
    }

    public function test_the_attribute_schema_response_matches_the_documented_shape(): void
    {
        ProductCategory::where('code', 'room_night')->update([
            'attribute_schema' => [
                'occupancy' => ['type' => 'int', 'label' => 'Max guests', 'mr_label' => 'पाहुणे', 'required' => true, 'min' => 1, 'max' => 20],
            ],
        ]);

        $vendor = $this->product->site->user;

        $response = $this->assertApiSuccess(
            $this->actingAs($vendor, 'api')->postJson('/api/v2/categoryAttributeSchema', [
                'product_category_id' => $this->product->product_category_id,
            ])
        );

        foreach (['id', 'name', 'mr_name', 'code', 'booking_type', 'attribute_schema'] as $field) {
            $this->assertArrayHasKey($field, $response->json('data'));
        }

        $this->assertSame('int', $response->json('data.attribute_schema.occupancy.type'));
        $this->assertSame('पाहुणे', $response->json('data.attribute_schema.occupancy.mr_label'));
    }

    public function test_per_page_is_clamped_rather_than_rejected(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/listProducts', ['per_page' => 500])
        );

        $this->assertSame(30, $response->json('data.per_page'), 'documented hard cap');
    }

    public function test_validation_errors_come_back_keyed_by_field(): void
    {
        $vendor = $this->product->site->user;

        $response = $this->actingAs($vendor, 'api')->postJson('/api/v2/addProduct', [
            'site_id' => $this->product->site_id,
            'product_category_id' => $this->product->product_category_id,
            // name omitted
        ]);

        $this->assertFalse($response->json('success'));
        $this->assertIsArray($response->json('message'), 'validation failures return field => errors');
        $this->assertArrayHasKey('name', $response->json('message'));
    }

    public function test_vendor_only_fields_are_ignored_when_posted(): void
    {
        $vendor = $this->product->site->user;

        $this->actingAs($vendor, 'api')->postJson('/api/v2/addProduct', [
            'site_id' => $this->product->site_id,
            'product_category_id' => $this->product->product_category_id,
            'name' => 'Sneaky Listing',
            'status' => 'approved', 'is_featured' => true,
            'is_bookable' => true, 'fulfilment_type' => 'order',
        ]);

        $created = Product::where('name', 'Sneaky Listing')->first();

        $this->assertSame('draft', $created->status);
        $this->assertFalse($created->is_featured);
        $this->assertFalse($created->is_bookable);
        $this->assertSame('enquiry', $created->fulfilment_type);
    }
}
