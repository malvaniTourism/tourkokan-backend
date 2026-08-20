<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Gallery;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Product galleries share the `galleries` table with places/sites but sit on a different
 * table shape — `products` has no `parent_id` and no `categories` relation. The place
 * gallery feeds eager-load `galleryable:id,name,parent_id` + `galleryable.categories`, so a
 * single product gallery row anywhere in range used to 500 the whole endpoint with
 * "Unknown column 'parent_id'". These pin that the product rows are excluded, not loaded.
 */
class GalleryProductMorphTest extends ApiTestCase
{
    private User $tourist;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tourist = $this->userWithRole('user');
        $vendor        = $this->userWithRole('vendor');

        $category = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->site = Site::create([
            'name' => 'Tarkarli Resort', 'description' => 'Test business.',
            'user_id' => $vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $this->site->categories()->attach($category->id);

        // A place gallery row — the kind these feeds are built for.
        Gallery::create([
            'title' => 'Sea view', 'path' => 'local/sites/a.jpg', 'status' => true,
            'galleryable_type' => $this->site->getMorphClass(), 'galleryable_id' => $this->site->id,
        ]);

        // A product gallery row — the one that used to crash the constrained morph load.
        $productCategory = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);
        $product = Product::create([
            'site_id' => $this->site->id, 'product_category_id' => $productCategory->id,
            'name' => 'Sea View Room', 'slug' => 'sea-view-room',
            'base_price' => 2400, 'status' => 'approved',
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);
        Gallery::create([
            'title' => 'Room photo', 'path' => 'local/products/b.jpg', 'status' => true,
            'galleryable_type' => $product->getMorphClass(), 'galleryable_id' => $product->id,
        ]);
    }

    public function test_public_gallery_excludes_product_rows_and_does_not_crash(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/getGallery')
        );

        $titles = collect($response->json('data.data'))->pluck('title');

        $this->assertContains('Sea view', $titles, 'Place gallery should be present.');
        $this->assertNotContains('Room photo', $titles, 'Product gallery must not leak into the place feed.');
    }

    public function test_landing_page_survives_a_product_gallery_row(): void
    {
        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/landingpage')
        );
    }
}
