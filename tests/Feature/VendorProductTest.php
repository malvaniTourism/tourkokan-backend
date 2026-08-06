<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * The vendor catalog: what a vendor can list, from which outlet, and what they may not
 * grant themselves.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §5 and §7.
 */
class VendorProductTest extends ApiTestCase
{
    private User $vendor;
    private Site $site;
    private ProductCategory $roomNight;
    private Category $hotelCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');

        $this->hotelCategory = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => 'x.png', 'status' => true,
        ]);

        $this->roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
            'attribute_schema' => [
                'occupancy' => ['type' => 'int', 'label' => 'Max guests', 'required' => true, 'min' => 1, 'max' => 20],
                'ac'        => ['type' => 'bool', 'label' => 'Air conditioned'],
            ],
        ]);

        AllowedProductCategory::create([
            'category_id' => $this->hotelCategory->id,
            'product_category_id' => $this->roomNight->id,
        ]);

        $this->site = $this->makeSite();
    }

    private function makeSite(array $overrides = [], ?array $categoryIds = null): Site
    {
        $site = Site::create(array_merge([
            'name'              => 'Outlet ' . uniqid(),
            'description'       => 'A Kokan business listing used for testing purposes.',
            'user_id'           => $this->vendor->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => 16.0,
            'longitude'         => 73.4,
        ], $overrides));

        $site->categories()->attach($categoryIds ?? [$this->hotelCategory->id]);

        return $site;
    }

    private function addProduct(array $overrides = [])
    {
        return $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addProduct', array_merge([
            'site_id'             => $this->site->id,
            'product_category_id' => $this->roomNight->id,
            'name'                => 'Deluxe Sea View Room',
            'base_price'          => 2400,
            'unit'                => 'per_night',
            'attributes'          => ['occupancy' => 3, 'ac' => 'true'],
        ], $overrides));
    }

    // ── Creating ─────────────────────────────────────────────────────────────────

    public function test_a_vendor_can_add_a_product_to_their_approved_site(): void
    {
        $response = $this->assertApiSuccess($this->addProduct());

        $this->assertDatabaseHas('products', [
            'site_id' => $this->site->id,
            'name'    => 'Deluxe Sea View Room',
            'status'  => 'draft',
            'slug'    => 'deluxe-sea-view-room',
        ]);
        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_a_new_product_always_gets_a_default_variant_carrying_the_price(): void
    {
        // R1 — price must live on a variant so a future per-date override has somewhere to
        // attach. See design doc §3.
        $this->addProduct();

        $product = Product::first();

        $this->assertCount(1, $product->variants, 'a default variant is created implicitly');
        $this->assertTrue($product->variants->first()->is_default);
        $this->assertSame('2400.00', $product->variants->first()->price);
        $this->assertSame('2400.00', $product->price, 'price resolves through the variant');
    }

    public function test_attributes_are_cast_despite_colliding_with_eloquents_internal_property(): void
    {
        // `attributes` is also Eloquent's internal storage property; reading it from
        // outside the model must still return the cast array.
        $this->addProduct();

        $stored = Product::first()->getAttribute('attributes');

        $this->assertSame(3, $stored['occupancy'], 'multipart string coerced to int');
        $this->assertTrue($stored['ac'], 'multipart "true" coerced to bool');
    }

    public function test_a_product_cannot_be_added_to_a_site_the_vendor_does_not_own(): void
    {
        $foreign = $this->makeSite(['user_id' => User::factory()->create()->id]);

        $this->assertApiFailure($this->addProduct(['site_id' => $foreign->id]), 403);
        $this->assertSame(0, Product::count());
    }

    public function test_a_product_can_be_added_to_a_site_still_under_review(): void
    {
        // Onboarding is one round trip, not two — see design doc §2.6. Nothing goes public
        // until both the site and the product are approved.
        $pending = $this->makeSite(['submission_status' => 'pending', 'status' => false]);

        $this->assertApiSuccess($this->addProduct(['site_id' => $pending->id]));
        $this->assertSame(0, Product::live()->count(), 'but it is not publicly visible');
    }

    public function test_a_product_cannot_be_added_to_a_rejected_site(): void
    {
        $rejected = $this->makeSite(['submission_status' => 'rejected', 'status' => false]);

        $this->assertApiFailure($this->addProduct(['site_id' => $rejected->id]), 403);
    }

    public function test_a_product_category_outside_the_sites_whitelist_is_refused(): void
    {
        $mango = ProductCategory::create([
            'name' => 'Alphonso', 'code' => 'alphonso', 'slug' => 'alphonso',
        ]);

        $this->assertApiFailure(
            $this->addProduct(['product_category_id' => $mango->id, 'attributes' => []]),
            422
        );
        $this->assertSame(0, Product::count());
    }

    public function test_invalid_attributes_block_creation(): void
    {
        $this->assertApiFailure($this->addProduct(['attributes' => ['occupancy' => 99]]), 422);
        $this->assertSame(0, Product::count(), 'nothing is persisted when attributes fail');
    }

    public function test_a_vendor_cannot_approve_or_feature_their_own_product(): void
    {
        $this->addProduct(['status' => 'approved', 'is_featured' => true]);

        $product = Product::first();

        $this->assertSame('draft', $product->status, 'status is not vendor-writable');
        $this->assertFalse($product->is_featured, 'is_featured is not vendor-writable');
        $this->assertFalse($product->is_bookable, 'is_bookable is not vendor-writable');
    }

    public function test_the_per_category_quota_is_enforced(): void
    {
        AllowedProductCategory::where('category_id', $this->hotelCategory->id)
            ->update(['max_products' => 1]);

        $this->assertApiSuccess($this->addProduct(['name' => 'First Room']));
        $this->assertApiFailure($this->addProduct(['name' => 'Second Room']), 422);

        $this->assertSame(1, Product::count());
    }

    public function test_slugs_are_unique_per_site_not_globally(): void
    {
        $this->addProduct(['name' => 'Fish Thali']);

        $otherSite = $this->makeSite();
        $this->addProduct(['name' => 'Fish Thali', 'site_id' => $otherSite->id]);

        $slugs = Product::orderBy('id')->pluck('slug')->all();

        $this->assertSame(['fish-thali', 'fish-thali'], $slugs, 'two vendors may both sell a Fish Thali');
    }

    public function test_a_repeated_name_within_one_site_gets_a_distinct_slug(): void
    {
        $this->addProduct(['name' => 'Fish Thali']);
        $this->addProduct(['name' => 'Fish Thali']);

        $this->assertSame(['fish-thali', 'fish-thali-1'], Product::orderBy('id')->pluck('slug')->all());
    }

    // ── Review lifecycle ─────────────────────────────────────────────────────────

    public function test_a_draft_can_be_submitted_for_review(): void
    {
        $this->addProduct();
        $product = Product::first();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/submitProductForReview', ['id' => $product->id])
        );

        $this->assertSame('pending', $product->fresh()->status);
    }

    public function test_a_product_missing_required_attributes_cannot_be_submitted(): void
    {
        $this->addProduct();
        $product = Product::first();
        $product->update(['attributes' => []]);

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/submitProductForReview', ['id' => $product->id]),
            422
        );
        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_editing_an_approved_product_sends_it_back_for_review(): void
    {
        $this->addProduct();
        $product = Product::first();
        $product->update(['status' => 'approved']);

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/updateProduct', ['id' => $product->id, 'name' => 'Renamed Room'])
        );

        $this->assertSame(
            'pending',
            $product->fresh()->status,
            'a vendor must not get a benign listing approved and then rewrite it'
        );
    }

    public function test_an_approved_product_can_be_paused_and_resumed(): void
    {
        $this->addProduct();
        $product = Product::first();
        $product->update(['status' => 'approved']);

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/toggleProductStatus', ['id' => $product->id]);
        $this->assertSame('paused', $product->fresh()->status);

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/toggleProductStatus', ['id' => $product->id]);
        $this->assertSame('approved', $product->fresh()->status);
    }

    public function test_a_draft_cannot_be_paused(): void
    {
        $this->addProduct();

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/toggleProductStatus', ['id' => Product::first()->id]),
            422
        );
    }

    // ── Cross-vendor isolation ───────────────────────────────────────────────────

    public function test_a_vendor_cannot_read_or_edit_another_vendors_product(): void
    {
        $this->addProduct();
        $product = Product::first();

        $intruder = $this->userWithRole('vendor');

        foreach (['getProduct', 'updateProduct', 'deleteProduct'] as $endpoint) {
            $this->assertApiFailure(
                $this->actingAs($intruder, 'api')->postJson("/api/v2/{$endpoint}", ['id' => $product->id]),
                404
            );
        }

        $this->assertNotNull($product->fresh(), 'the product survives');
    }

    public function test_my_products_lists_only_the_callers_products(): void
    {
        $this->addProduct(['name' => 'Mine']);

        $intruder = $this->userWithRole('vendor');
        $response = $this->assertApiSuccess(
            $this->actingAs($intruder, 'api')->postJson('/api/v2/myProducts')
        );

        $this->assertSame([], $response->json('data.data'));
    }

    // ── Variants ─────────────────────────────────────────────────────────────────

    public function test_adding_a_variant_and_promoting_it_to_default(): void
    {
        $this->addProduct();
        $product = Product::first();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/saveProductVariant', [
                'id' => $product->id, 'name' => 'Non-AC', 'price' => 1800, 'is_default' => true,
            ])
        );

        $product->refresh();

        $this->assertCount(2, $product->variants);
        $this->assertSame(
            1,
            $product->variants()->where('is_default', true)->count(),
            'exactly one variant may be default'
        );
        $this->assertSame('1800.00', $product->price);
    }

    public function test_the_last_variant_cannot_be_deleted(): void
    {
        // R1 — without a variant the product has no authoritative price.
        $this->addProduct();
        $product = Product::first();

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/deleteProductVariant', [
                'id' => $product->id, 'variant_id' => $product->variants->first()->id,
            ]),
            422
        );
    }

    public function test_deleting_the_default_variant_promotes_another(): void
    {
        $this->addProduct();
        $product = Product::first();

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/saveProductVariant', [
            'id' => $product->id, 'name' => 'Non-AC', 'price' => 1800,
        ]);

        $default = $product->variants()->where('is_default', true)->first();

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/deleteProductVariant', [
            'id' => $product->id, 'variant_id' => $default->id,
        ]);

        $this->assertSame(
            1,
            $product->fresh()->variants()->where('is_default', true)->count(),
            'a product is never left without a default variant'
        );
    }

    public function test_a_sale_price_above_the_base_price_is_refused(): void
    {
        $this->assertApiFailure($this->addProduct(['base_price' => 1000, 'sale_price' => 1500]), 422);
    }

    // ── Media ────────────────────────────────────────────────────────────────────

    public function test_the_first_uploaded_image_becomes_the_cover(): void
    {
        Storage::fake();
        $this->addProduct();
        $product = Product::first();

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->assertApiSuccess(
                $this->actingAs($this->vendor, 'api')->postJson('/api/v2/uploadProductMedia', [
                    'id' => $product->id, 'image' => UploadedFile::fake()->image($name),
                ])
            );
        }

        $product->refresh();

        $this->assertCount(2, $product->gallery);
        $this->assertSame(1, $product->gallery()->where('is_cover', true)->count());
        $this->assertSame([1, 2], $product->gallery->pluck('sort_order')->all());
    }

    public function test_deleting_the_cover_promotes_the_next_image(): void
    {
        Storage::fake();
        $this->addProduct();
        $product = Product::first();

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/uploadProductMedia', [
                'id' => $product->id, 'image' => UploadedFile::fake()->image($name),
            ]);
        }

        $cover = $product->gallery()->where('is_cover', true)->first();

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/deleteProductMedia', [
            'id' => $product->id, 'media_id' => $cover->id,
        ]);

        $this->assertSame(
            1,
            $product->fresh()->gallery()->where('is_cover', true)->count(),
            'a product with images always has a cover'
        );
    }

    public function test_a_vendor_cannot_upload_media_to_another_vendors_product(): void
    {
        Storage::fake();
        $this->addProduct();

        $intruder = $this->userWithRole('vendor');

        $this->assertApiFailure(
            $this->actingAs($intruder, 'api')->postJson('/api/v2/uploadProductMedia', [
                'id' => Product::first()->id, 'image' => UploadedFile::fake()->image('x.jpg'),
            ]),
            404
        );
    }

    public function test_the_catalog_is_closed_to_users_without_the_vendor_role(): void
    {
        $plain = $this->userWithRole('user');

        $this->actingAs($plain, 'api')->postJson('/api/v2/myProducts')->assertStatus(403);
        $this->actingAs($plain, 'api')->postJson('/api/v2/addProduct')->assertStatus(403);
    }
}
