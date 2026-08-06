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
 * Guards the commerce-readiness contract — the parts of §3b (C1–C5) that are cheap now and
 * expensive once real orders exist.
 *
 * Selling through the platform is not built. These tests protect the shape that keeps it a
 * pure addition. See docs/VENDOR_PRODUCTS_DESIGN.md §3b.
 */
class CommerceReadinessTest extends ApiTestCase
{
    private User $vendor;
    private Site $site;
    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');

        $siteCategory = Category::create([
            'name' => 'Grocery Store', 'mr_name' => 'किराणा', 'code' => 'grocery_store',
            'icon' => '', 'status' => true,
        ]);

        $this->category = ProductCategory::create([
            'name' => 'Shop Item', 'code' => 'retail_item', 'slug' => 'shop-item',
            'booking_type' => 'quantity',
        ]);

        AllowedProductCategory::create([
            'category_id' => $siteCategory->id, 'product_category_id' => $this->category->id,
        ]);

        $this->site = Site::create([
            'name' => 'Kokan Kirana', 'description' => 'A Kokan shop used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.0, 'longitude' => 73.4,
        ]);
        $this->site->categories()->attach($siteCategory->id);
    }

    private function addProduct(array $overrides = [])
    {
        return $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addProduct', array_merge([
            'site_id'             => $this->site->id,
            'product_category_id' => $this->category->id,
            'name'                => 'Kokum Syrup 500ml',
            'base_price'          => 180,
            'unit'                => 'per_piece',
        ], $overrides));
    }

    // ── C1: the variant is the unit of sale ──────────────────────────────────────

    public function test_every_product_has_a_priced_variant_an_order_line_could_reference(): void
    {
        $this->addProduct();

        $variant = Product::first()->defaultVariant;

        $this->assertNotNull($variant, 'order lines reference a variant, never a product');
        $this->assertSame('180.00', $variant->price);
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('product_variants', 'sku'),
            'a sellable unit needs an SKU'
        );
    }

    // ── C3: tax is recorded before the first order ───────────────────────────────

    public function test_a_vendor_can_record_gst_details_while_listing(): void
    {
        $this->assertApiSuccess($this->addProduct([
            'hsn_code' => '20079990', 'tax_rate' => 12, 'price_includes_tax' => true,
        ]));

        $product = Product::first();

        $this->assertSame('20079990', $product->hsn_code);
        $this->assertSame('12.00', $product->tax_rate);
        $this->assertTrue($product->price_includes_tax);
    }

    public function test_a_tax_rate_outside_the_gst_slabs_is_refused(): void
    {
        // A free-text rate becomes a tax liability the moment order lines snapshot it.
        $this->assertApiFailure($this->addProduct(['tax_rate' => 7.5]), 422);
    }

    public function test_a_non_numeric_hsn_code_is_refused(): void
    {
        $this->assertApiFailure($this->addProduct(['hsn_code' => 'ABC123']), 422);
    }

    // ── C4: fulfilment is not vendor-writable until commerce exists ──────────────

    public function test_listings_default_to_enquiry_only(): void
    {
        $this->addProduct();

        $this->assertSame('enquiry', Product::first()->fulfilment_type);
    }

    public function test_a_vendor_cannot_make_their_own_listing_orderable(): void
    {
        $this->addProduct(['fulfilment_type' => 'order']);

        $this->assertSame(
            'enquiry',
            Product::first()->fulfilment_type,
            'enabling commerce is a platform decision, not a field a vendor can post'
        );
    }

    // ── Order quantity bounds ────────────────────────────────────────────────────

    public function test_order_quantity_bounds_can_be_set_on_a_variant(): void
    {
        $this->addProduct();
        $product = Product::first();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/saveProductVariant', [
                'id' => $product->id, 'name' => 'Case of 12', 'price' => 1900,
                'stock' => 40, 'min_order_qty' => 1, 'max_order_qty' => 5,
            ])
        );

        $variant = $product->fresh()->variants()->where('name', 'Case of 12')->first();

        $this->assertSame(1, $variant->min_order_qty);
        $this->assertSame(5, $variant->max_order_qty);
    }

    public function test_a_max_below_the_min_order_quantity_is_refused(): void
    {
        $this->addProduct();

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/saveProductVariant', [
                'id' => Product::first()->id, 'name' => 'Bad bounds', 'price' => 100,
                'min_order_qty' => 10, 'max_order_qty' => 2,
            ]),
            422
        );
    }

    // ── C5: the seller is derivable for payouts ──────────────────────────────────

    public function test_the_seller_is_reachable_from_a_product_in_one_hop(): void
    {
        // Commission and settlement attach here. One ownership column means a payout can
        // never be ambiguous about who gets paid.
        $this->addProduct();

        $this->assertSame($this->vendor->id, Product::first()->site->user_id);
    }
}
