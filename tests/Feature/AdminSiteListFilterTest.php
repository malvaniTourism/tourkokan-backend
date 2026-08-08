<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * The admin site list, and specifically its `global` filter.
 *
 * `global` means "an actual place, not one of the geographic containers" — District, City,
 * Village — which were the only parentless rows when it was written. Vendor businesses are
 * parentless too, because submitSite does not ask for a geographic parent, so the original
 * whereNotNull('parent_id') hid every vendor listing from the admin.
 */
class AdminSiteListFilterTest extends ApiTestCase
{
    private User $admin;
    private Category $hotelRooms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin      = $this->userWithRole('admin');
        $this->hotelRooms = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);
    }

    private function site(string $name, array $overrides = [], ?Category $category = null): Site
    {
        $site = Site::create(array_merge([
            'name' => $name, 'description' => 'A Kokan listing used for testing purposes.',
            'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ], $overrides));

        $site->categories()->attach(($category ?? $this->hotelRooms)->id);

        return $site;
    }

    private function list(array $payload = [])
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson('/admin/v2/sites', array_merge(['apitype' => 'list'], $payload));
    }

    public function test_a_vendor_business_is_visible_with_the_global_filter(): void
    {
        $vendor  = $this->userWithRole('vendor');
        $village = $this->site('Tarkarli', ['user_id' => null]);

        $this->site('Sagar Resort', ['user_id' => $vendor->id]);          // vendor, no parent
        $this->site('Tarkarli Beach', ['parent_id' => $village->id]);      // platform place

        $names = collect($this->assertApiSuccess($this->list(['global' => 1]))->json('data.data'))
            ->pluck('name');

        $this->assertContains('Sagar Resort', $names, 'a vendor business is a place too');
        $this->assertContains('Tarkarli Beach', $names);
        $this->assertNotContains('Tarkarli', $names, 'the village container stays hidden');
    }

    public function test_filtering_by_category_id(): void
    {
        $other = Category::create([
            'name' => 'Restaurant', 'mr_name' => 'हॉटेल', 'code' => 'restaurant',
            'icon' => '', 'status' => true,
        ]);

        $this->site('Sagar Resort');
        $this->site('Malvani Katta', [], $other);

        $names = collect($this->assertApiSuccess($this->list(['category_id' => $this->hotelRooms->id]))->json('data.data'))
            ->pluck('name');

        $this->assertSame(['Sagar Resort'], $names->all());
    }

    public function test_filtering_by_category_code_still_works(): void
    {
        $this->site('Sagar Resort');

        $names = collect($this->list(['category' => 'hotel_rooms'])->json('data.data'))->pluck('name');

        $this->assertSame(['Sagar Resort'], $names->all());
    }

    public function test_category_id_and_global_combine(): void
    {
        // The exact call the admin panel makes.
        $vendor = $this->userWithRole('vendor');
        $this->site('Sagar Resort', ['user_id' => $vendor->id]);

        $rows = $this->assertApiSuccess($this->list([
            'global' => 1, 'category_id' => $this->hotelRooms->id,
        ]))->json('data.data');

        $this->assertCount(1, $rows);
        $this->assertSame('Sagar Resort', $rows[0]['name']);
    }

    public function test_global_zero_does_not_filter(): void
    {
        // has() was true for any present key, so "global: off" still applied the filter.
        $this->site('Tarkarli', ['user_id' => null]);

        $names = collect($this->list(['global' => 0])->json('data.data'))->pluck('name');

        $this->assertContains('Tarkarli', $names);
    }

    public function test_an_unknown_category_id_is_rejected(): void
    {
        $this->assertApiFailure($this->list(['category_id' => 999999]));
    }
}
