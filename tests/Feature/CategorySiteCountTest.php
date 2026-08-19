<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "N places in this category" figure must match what a tourist can actually open —
 * approved and published, not submissions under review or rows left by a deleted site.
 */
class CategorySiteCountTest extends TestCase
{
    use RefreshDatabase;

    private Category $parent;
    private Category $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = Category::create(['name' => 'Accommodation', 'mr_name' => 'निवास', 'code' => 'accomodation',
                                          'icon' => '', 'status' => true]);
        $this->child  = Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
                                          'parent_id' => $this->parent->id, 'icon' => '', 'status' => true]);
    }

    private function site(array $overrides = []): Site
    {
        $site = Site::create(array_merge([
            'name' => 'S' . uniqid(), 'description' => 'A Kokan business used for testing purposes.',
            'status' => true, 'submission_status' => 'approved', 'latitude' => 16, 'longitude' => 73,
        ], $overrides));
        $site->categories()->attach($this->child->id);
        return $site;
    }

    public function test_count_includes_only_approved_and_published_sites(): void
    {
        $this->site();                                                        // live
        $this->site(['submission_status' => 'pending', 'status' => false]);   // under review
        $this->site(['submission_status' => 'rejected', 'status' => false]);  // rejected
        $this->site(['status' => false]);                                     // unpublished

        $result = app(CategoryService::class)->getPaginated('accomodation', 1, 15, ['paginate' => false]);
        $child  = $result->firstWhere('code', 'accomodation')->subCategories->firstWhere('code', 'hotel_rooms');

        $this->assertSame(1, $child->sites_count, 'only the one live site counts');
    }

    public function test_an_orphaned_pivot_row_is_not_counted(): void
    {
        $live  = $this->site();
        $ghost = $this->site();
        \Illuminate\Support\Facades\DB::table('sites')->where('id', $ghost->id)->delete(); // leave pivot

        $result = app(CategoryService::class)->getPaginated('accomodation', 1, 15, ['paginate' => false]);
        $child  = $result->firstWhere('code', 'accomodation')->subCategories->firstWhere('code', 'hotel_rooms');

        $this->assertSame(1, $child->sites_count, 'the orphaned category_site row must not inflate it');
    }
}
