<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Gallery;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Site is registered in the morph map (AppServiceProvider), so its gallery rows store
 * `galleryable_type = 'site'` rather than the fully-qualified class name. Three endpoints
 * compared that column against `Site::class` instead, which can never match a mapped
 * alias: both delete endpoints reported "not found" for rows that existed, and filtering
 * the public gallery by site returned nothing at all.
 *
 * These pin the alias, so re-introducing `Site::class` here fails rather than silently
 * emptying a feed.
 */
class GallerySiteMorphTest extends ApiTestCase
{
    private User $admin;
    private User $vendor;
    private User $tourist;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = $this->userWithRole('admin');
        $this->tourist = $this->userWithRole('user');
        $this->vendor  = $this->userWithRole('vendor');

        $category = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->site = Site::create([
            'name' => 'Tarkarli Resort', 'description' => 'A Kokan business used for testing.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $this->site->categories()->attach($category->id);
    }

    private function photo(): Gallery
    {
        return $this->site->gallery()->create([
            'title'  => 'Sea facing room',
            'path'   => 'testing/sites/gallery/room.jpg',
            'is_url' => false,
            'status' => true,
        ]);
    }

    public function test_a_site_gallery_row_is_stored_under_the_morph_alias(): void
    {
        $this->assertSame('site', $this->photo()->galleryable_type);
    }

    public function test_admin_can_delete_a_site_gallery_image(): void
    {
        $photo = $this->photo();

        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/deleteSiteGallery', ['gallery_id' => $photo->id])
        );

        $this->assertDatabaseMissing('galleries', ['id' => $photo->id]);
    }

    public function test_a_vendor_can_delete_a_photo_from_their_own_site(): void
    {
        $photo = $this->photo();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/deleteSiteGallery', ['gallery_id' => $photo->id])
        );

        $this->assertDatabaseMissing('galleries', ['id' => $photo->id]);
    }

    public function test_a_vendor_cannot_delete_a_photo_from_someone_elses_site(): void
    {
        $photo = $this->photo();

        $this->assertApiFailure(
            $this->actingAs($this->userWithRole('vendor'), 'api')
                ->postJson('/api/v2/deleteSiteGallery', ['gallery_id' => $photo->id])
        );

        $this->assertDatabaseHas('galleries', ['id' => $photo->id]);
    }

    public function test_the_public_gallery_can_be_filtered_to_one_site(): void
    {
        $photo = $this->photo();

        $response = $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')
                ->postJson('/api/v2/getGallery', ['site_id' => $this->site->id])
        );

        $ids = collect($response->json('data.data') ?? $response->json('data'))->pluck('id');

        $this->assertContains($photo->id, $ids);
    }
}
