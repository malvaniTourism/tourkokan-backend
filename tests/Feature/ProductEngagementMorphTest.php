<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * Products participate in the platform's existing Comment / Rating / Favourite morphs.
 *
 * Those controllers are generic — they resolve a type string through getData(), so a model
 * missing a case there declares the relations but is unreachable through the API.
 */
class ProductEngagementMorphTest extends ApiTestCase
{
    private User $tourist;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tourist = $this->userWithRole('user');
        $vendor        = $this->userWithRole('vendor');

        Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms', 'icon' => '', 'status' => true]);
        $pc = ProductCategory::create(['name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night']);

        $site = Site::create([
            'name' => 'Tarkarli Resort', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);

        $this->product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $pc->id,
            'name' => 'Sea View Suite', 'slug' => 'sea-view-suite',
            'base_price' => 2400, 'status' => 'approved',
        ]);

        ProductVariant::create([
            'product_id' => $this->product->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);
    }

    public function test_a_product_can_be_favourited(): void
    {
        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/addDeleteFavourite', [
                'favouritable_id' => $this->product->id, 'favouritable_type' => 'Product',
            ])
        );

        $this->assertSame(1, $this->product->favourites()->count());
    }

    public function test_a_product_can_be_rated(): void
    {
        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/addUpdateRating', [
                'rateable_id' => $this->product->id, 'rateable_type' => 'Product', 'rate' => 5,
            ])
        );

        $this->assertSame(1, $this->product->rating()->count());
    }

    public function test_a_product_can_be_commented_on(): void
    {
        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/comment', [
                'commentable_id' => $this->product->id, 'commentable_type' => 'Product',
                'comment' => 'Lovely room, great view of the bay.',
            ])
        );

        // Comments are moderated platform-wide — comment() filters status = true, so a new
        // one is stored but not yet visible until an admin approves it.
        $this->assertDatabaseHas('comments', [
            'commentable_type' => \App\Models\Product::class,
            'commentable_id'   => $this->product->id,
            'status'           => 0,
        ]);
        $this->assertSame(0, $this->product->comment()->count(), 'not visible until approved');

        \App\Models\Comment::query()->update(['status' => true]);

        $this->assertSame(1, $this->product->comment()->count());
    }

    public function test_a_listing_that_is_not_live_cannot_be_engaged_with(): void
    {
        $this->product->update(['status' => 'draft']);

        $this->assertApiFailure(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/addDeleteFavourite', [
                'favouritable_id' => $this->product->id, 'favouritable_type' => 'Product',
            ])
        );

        $this->assertSame(0, $this->product->favourites()->count());
    }

    public function test_product_detail_reflects_the_callers_own_favourite(): void
    {
        $this->actingAs($this->tourist, 'api')->postJson('/api/v2/addDeleteFavourite', [
            'favouritable_id' => $this->product->id, 'favouritable_type' => 'Product',
        ]);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/productDetail', ['id' => $this->product->id])
        );

        $this->assertTrue($response->json('data.is_favourite'));
    }

    public function test_media_can_be_reordered_by_the_owner(): void
    {
        Storage::fake();
        $vendor = $this->product->site->user;

        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $name) {
            $this->actingAs($vendor, 'api')->postJson('/api/v2/uploadProductMedia', [
                'id' => $this->product->id, 'image' => UploadedFile::fake()->image($name),
            ]);
        }

        $ids = $this->product->fresh()->gallery->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->assertApiSuccess(
            $this->actingAs($vendor, 'api')->postJson('/api/v2/reorderProductMedia', [
                'id' => $this->product->id, 'media_ids' => $reversed,
            ])
        );

        $this->assertSame($reversed, $this->product->fresh()->gallery->pluck('id')->all());
        $this->assertSame([1, 2, 3], $this->product->fresh()->gallery->pluck('sort_order')->all());
    }

    public function test_a_partial_reorder_is_rejected_rather_than_half_applied(): void
    {
        Storage::fake();
        $vendor = $this->product->site->user;

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->actingAs($vendor, 'api')->postJson('/api/v2/uploadProductMedia', [
                'id' => $this->product->id, 'image' => UploadedFile::fake()->image($name),
            ]);
        }

        $ids = $this->product->fresh()->gallery->pluck('id')->all();

        $this->assertApiFailure(
            $this->actingAs($vendor, 'api')->postJson('/api/v2/reorderProductMedia', [
                'id' => $this->product->id, 'media_ids' => [$ids[0]],
            ]),
            422
        );
    }
}
