<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\ProductCategory;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * The whitelist is what stops a hospital listing mangoes, and the attribute schema is what
 * lets a new vertical ship without an app release.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §2.5 and §6.
 */
class ProductTaxonomyTest extends ApiTestCase
{
    private User $vendor;
    private User $admin;

    private Category $hotelCategory;
    private Category $hospitalCategory;

    private ProductCategory $roomNight;
    private ProductCategory $stayPackage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');
        $this->admin  = $this->userWithRole('admin');

        $this->hotelCategory    = $this->siteCategory('Hotel Rooms', 'hotel_rooms');
        $this->hospitalCategory = $this->siteCategory('Hospital', 'hospital');

        $this->roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
            'attribute_schema' => [
                'occupancy' => ['type' => 'int', 'label' => 'Max guests', 'required' => true, 'min' => 1, 'max' => 20],
                'ac'        => ['type' => 'bool', 'label' => 'Air conditioned'],
            ],
        ]);

        $this->stayPackage = ProductCategory::create([
            'name' => 'Stay Package', 'code' => 'stay_package', 'slug' => 'stay-package',
            'booking_type' => 'date_range',
        ]);

        AllowedProductCategory::create([
            'category_id' => $this->hotelCategory->id, 'product_category_id' => $this->roomNight->id,
        ]);
        AllowedProductCategory::create([
            'category_id' => $this->hotelCategory->id, 'product_category_id' => $this->stayPackage->id,
        ]);
    }

    private function siteCategory(string $name, string $code): Category
    {
        return Category::create([
            'name' => $name, 'mr_name' => $name, 'code' => $code, 'icon' => 'x.png', 'status' => true,
        ]);
    }

    private function approvedSite(array $categoryIds, ?int $userId = null): Site
    {
        $site = Site::create([
            'name'              => 'Outlet ' . uniqid(),
            'description'       => 'A Kokan business listing used for testing purposes.',
            'user_id'           => $userId ?? $this->vendor->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => 16.0,
            'longitude'         => 73.4,
        ]);

        $site->categories()->attach($categoryIds);

        return $site;
    }

    // ── The whitelist ────────────────────────────────────────────────────────────

    public function test_a_site_may_only_list_product_categories_allowed_for_its_categories(): void
    {
        $site = $this->approvedSite([$this->hotelCategory->id]);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id])
        );

        $codes = collect($response->json('data'))->pluck('code')->sort()->values()->all();

        $this->assertSame(['room_night', 'stay_package'], $codes);
    }

    public function test_a_site_category_with_no_whitelist_entries_can_list_nothing(): void
    {
        $site = $this->approvedSite([$this->hospitalCategory->id]);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id])
        );

        $this->assertSame([], $response->json('data'), 'a hospital must not be able to list mangoes');
    }

    public function test_a_product_category_reachable_through_two_site_categories_appears_once(): void
    {
        $second = $this->siteCategory('Resort', 'resort');
        AllowedProductCategory::create([
            'category_id' => $second->id, 'product_category_id' => $this->roomNight->id,
        ]);

        $site = $this->approvedSite([$this->hotelCategory->id, $second->id]);

        $response = $this->actingAs($this->vendor, 'api')
            ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id]);

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertSame($codes->unique()->count(), $codes->count(), 'results must be de-duplicated');
    }

    public function test_inactive_product_categories_are_not_offered(): void
    {
        $this->stayPackage->update(['status' => false]);

        $site = $this->approvedSite([$this->hotelCategory->id]);

        $response = $this->actingAs($this->vendor, 'api')
            ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id]);

        $this->assertSame(['room_night'], collect($response->json('data'))->pluck('code')->all());
    }

    public function test_a_vendor_cannot_read_the_whitelist_of_a_site_they_do_not_own(): void
    {
        $foreign = $this->approvedSite([$this->hotelCategory->id], User::factory()->create()->id);

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $foreign->id]),
            404
        );
    }

    public function test_the_picker_works_for_a_site_still_under_review(): void
    {
        // A vendor builds their catalog while the business is pending — see design doc §2.6.
        $site = $this->approvedSite([$this->hotelCategory->id]);
        $site->update(['submission_status' => 'pending', 'status' => false]);

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id])
        );
    }

    public function test_a_rejected_site_has_no_picker(): void
    {
        $site = $this->approvedSite([$this->hotelCategory->id]);
        $site->update(['submission_status' => 'rejected', 'status' => false]);

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id]),
            404
        );
    }

    // ── Attribute schema delivery ────────────────────────────────────────────────

    public function test_the_app_can_fetch_the_schema_it_renders_its_form_from(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/categoryAttributeSchema', ['product_category_id' => $this->roomNight->id])
        );

        $this->assertSame('date_range', $response->json('data.booking_type'));
        $this->assertSame('int', $response->json('data.attribute_schema.occupancy.type'));
        $this->assertSame('Max guests', $response->json('data.attribute_schema.occupancy.label'));
    }

    public function test_a_category_without_a_schema_returns_an_empty_object_not_null(): void
    {
        $response = $this->actingAs($this->vendor, 'api')
            ->postJson('/api/v2/categoryAttributeSchema', ['product_category_id' => $this->stayPackage->id]);

        $this->assertSame([], $response->json('data.attribute_schema'));
    }

    // ── Admin taxonomy management ────────────────────────────────────────────────

    public function test_admin_can_create_a_category_which_ships_a_new_vertical(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/addProductCategory', [
                'name'         => 'Alphonso Mango',
                'code'         => 'alphonso_mango',
                'booking_type' => 'quantity',
                'attribute_schema' => [
                    'grade' => ['type' => 'enum', 'label' => 'Grade', 'required' => true, 'options' => ['A', 'B']],
                ],
            ])
        );

        $this->assertDatabaseHas('product_categories', [
            'code' => 'alphonso_mango', 'slug' => 'alphonso-mango', 'booking_type' => 'quantity',
        ]);
        $this->assertSame('enum', $response->json('data.attribute_schema.grade.type'));
    }

    public function test_admin_cannot_create_a_category_with_a_malformed_schema(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/addProductCategory', [
                'name' => 'Broken', 'code' => 'broken',
                'attribute_schema' => ['x' => ['type' => 'unicorn', 'label' => 'X']],
            ]),
            422
        );

        $this->assertDatabaseMissing('product_categories', ['code' => 'broken']);
    }

    /**
     * R5 — see design doc §3. Price varies by date, so it belongs in pricing/availability.
     */
    public function test_admin_cannot_smuggle_a_reserved_key_into_a_schema(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/addProductCategory', [
                'name' => 'Sneaky', 'code' => 'sneaky',
                'attribute_schema' => ['price' => ['type' => 'decimal', 'label' => 'Price']],
            ]),
            422
        );

        $this->assertDatabaseMissing('product_categories', ['code' => 'sneaky']);
    }

    public function test_category_codes_are_unique(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/addProductCategory', [
                'name' => 'Duplicate', 'code' => 'room_night',
            ]),
            422
        );
    }

    public function test_setting_the_whitelist_replaces_the_previous_set(): void
    {
        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/setAllowedProductCategories', [
                'category_id' => $this->hotelCategory->id,
                'allowed'     => [
                    ['product_category_id' => $this->roomNight->id, 'max_products' => 50],
                ],
            ])
        );

        $rows = AllowedProductCategory::where('category_id', $this->hotelCategory->id)->get();

        $this->assertCount(1, $rows, 'the previous two entries are replaced, not appended to');
        $this->assertSame($this->roomNight->id, $rows->first()->product_category_id);
        $this->assertSame(50, $rows->first()->max_products);
    }

    public function test_setting_an_empty_whitelist_revokes_everything(): void
    {
        $this->actingAs($this->admin, 'api')->postJson('/admin/v2/setAllowedProductCategories', [
            'category_id' => $this->hotelCategory->id,
            'allowed'     => [],
        ]);

        $this->assertSame(0, AllowedProductCategory::where('category_id', $this->hotelCategory->id)->count());
    }

    public function test_duplicate_entries_in_one_whitelist_payload_are_rejected(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/setAllowedProductCategories', [
                'category_id' => $this->hotelCategory->id,
                'allowed'     => [
                    ['product_category_id' => $this->roomNight->id],
                    ['product_category_id' => $this->roomNight->id],
                ],
            ]),
            422
        );
    }

    public function test_a_category_with_children_cannot_be_deleted(): void
    {
        ProductCategory::create([
            'name' => 'Deluxe Room', 'code' => 'deluxe_room', 'slug' => 'deluxe-room',
            'parent_id' => $this->roomNight->id,
        ]);

        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/deleteProductCategory', ['id' => $this->roomNight->id]),
            422
        );

        $this->assertDatabaseHas('product_categories', ['id' => $this->roomNight->id, 'deleted_at' => null]);
    }

    public function test_taxonomy_management_is_closed_to_non_admins(): void
    {
        $this->actingAs($this->vendor, 'api')
            ->postJson('/admin/v2/addProductCategory', ['name' => 'Nope', 'code' => 'nope'])
            ->assertStatus(403);
    }
}
