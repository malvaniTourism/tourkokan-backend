<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Favourite;
use App\Models\Gallery;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLead;
use App\Models\ProductVariant;
use App\Models\Rating;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a site must take its whole subtree with it.
 *
 * FK cascades handle the FK-backed children (products, variants, stats, leads, pivots);
 * SiteDeletionCleaner handles the polymorphic ones (gallery, comments, ratings, favourites,
 * contacts, banners) for the site and its products, which carry no FK and would orphan.
 */
class SiteDeletionCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): array
    {
        $user = User::factory()->create();
        $cat  = Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
                                  'icon' => '', 'status' => true]);
        $pc   = ProductCategory::create(['name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night']);

        $site = Site::create([
            'name' => 'Sagar Resort', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $user->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $site->categories()->attach($cat->id);
        $site->gallery()->create(['title' => 'site', 'path' => 'x/site.png', 'status' => true]);

        $product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $pc->id,
            'name' => 'Room', 'slug' => 'room', 'base_price' => 100, 'status' => 'approved',
        ]);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Std', 'price' => 100,
                                'is_default' => true, 'status' => true]);
        $product->gallery()->create(['title' => 'p', 'path' => 'x/p.png', 'status' => true]);
        $product->comment()->create(['user_id' => $user->id, 'comment' => 'nice', 'status' => true]);
        $product->rating()->create(['user_id' => $user->id, 'rate' => 5, 'status' => true]);
        Favourite::create(['user_id' => $user->id, 'favouritable_type' => Product::class,
                           'favouritable_id' => $product->id]);
        ProductLead::create(['product_id' => $product->id, 'lead_type' => 'call']);

        return [$site, $product];
    }

    public function test_deleting_a_site_removes_its_products_and_all_their_children(): void
    {
        [$site, $product] = $this->tree();

        $site->delete();

        // FK-backed
        $this->assertSame(0, Product::withTrashed()->where('site_id', $site->id)->count());
        $this->assertSame(0, ProductVariant::where('product_id', $product->id)->count());
        $this->assertSame(0, ProductLead::where('product_id', $product->id)->count());
        $this->assertDatabaseMissing('category_site', ['site_id' => $site->id]);

        // morph — the ones that would orphan
        $this->assertSame(0, Gallery::where('galleryable_id', $product->id)->count(), 'product gallery');
        $this->assertSame(0, Gallery::where('galleryable_id', $site->id)
            ->where('galleryable_type', $site->getMorphClass())->count(), 'site gallery');
        $this->assertSame(0, Comment::where('commentable_id', $product->id)->count());
        $this->assertSame(0, Rating::where('rateable_id', $product->id)->count());
        $this->assertSame(0, Favourite::where('favouritable_id', $product->id)->count());
    }

    public function test_it_matches_the_morph_alias_not_the_class_name(): void
    {
        // Regression: `site` is aliased in the morph map, so a class-name match misses the
        // site's own gallery. This asserts the alias path specifically.
        [$site] = $this->tree();
        $this->assertSame('site', $site->getMorphClass(), 'precondition: site is aliased');

        $site->delete();

        $this->assertSame(0, Gallery::count(), 'both aliased-site and class-name product galleries gone');
    }

    public function test_a_site_with_no_products_deletes_cleanly(): void
    {
        $user = User::factory()->create();
        $site = Site::create([
            'name' => 'Empty', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $user->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16, 'longitude' => 73,
        ]);
        $site->gallery()->create(['title' => 's', 'path' => 'x/s.png', 'status' => true]);

        $site->delete();

        $this->assertModelMissing($site);
        $this->assertSame(0, Gallery::count());
    }
}
