<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the Phase 1 demolition.
 *
 * `sites` replaced `projects` in 2023, and the three product verticals built on top of it
 * were never finished. These assertions exist so the legacy shapes cannot quietly return —
 * a reintroduced `projects` FK is exactly how the 2022 code ended up unusable.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §4.
 */
class LegacySchemaRemovedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function retiredTables(): array
    {
        return [
            'projects'                => ['projects'],
            'accomodations'           => ['accomodations'],
            'accomodation_categories' => ['accomodation_categories'],
            'tour_packages'           => ['tour_packages'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retiredTables')]
    public function test_retired_tables_do_not_exist(string $table): void
    {
        $this->assertFalse(
            Schema::hasTable($table),
            "`{$table}` was retired in Phase 1 and must not be recreated."
        );
    }

    public function test_project_id_columns_are_gone_from_surviving_tables(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'project_id'));
        $this->assertFalse(Schema::hasColumn('photos', 'project_id'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function retiredClasses(): array
    {
        return [
            'Projects model'      => ['App\Models\Projects'],
            'Accomodation model'  => ['App\Models\Accomodation'],
            'TourPackage model'   => ['App\Models\TourPackage'],
            'Admin ProductController'      => ['App\Http\Controllers\Admin\ProductController'],
            'Admin TourPackageController'  => ['App\Http\Controllers\Admin\TourPackageController'],
            'Admin AccomodationController' => ['App\Http\Controllers\Admin\AccomodationController'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retiredClasses')]
    public function test_retired_classes_do_not_exist(string $class): void
    {
        $this->assertFalse(class_exists($class), "`{$class}` was deleted in Phase 1.");
    }

    public function test_the_taxonomy_tables_kept_for_the_rebuild_survived(): void
    {
        $this->assertTrue(Schema::hasTable('product_categories'));
        $this->assertTrue(Schema::hasTable('allowed_product_categories'));
        $this->assertTrue(Schema::hasTable('sites'));
    }

    public function test_the_accomodation_morph_alias_is_gone(): void
    {
        $morphMap = \Illuminate\Database\Eloquent\Relations\Relation::morphMap();

        $this->assertArrayNotHasKey('accomodation', $morphMap);
        $this->assertArrayHasKey('site', $morphMap, 'the site alias must survive');
    }

    public function test_product_categories_no_longer_require_icon_and_meta_data(): void
    {
        // The 2022 schema had both NOT NULL with no default while the controller treated
        // them as optional, so every insert without them failed at the database.
        $category = \App\Models\ProductCategory::create([
            'name' => 'Schema Check',
            'code' => 'schema_check',
            'slug' => 'schema-check',
        ]);

        $this->assertNull($category->icon);
        $this->assertNull($category->meta_data);
    }
}
