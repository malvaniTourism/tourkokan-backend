<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the legacy `projects` entity and the three half-built 2022-era product verticals.
 *
 * All affected tables were verified empty before this migration was written:
 *   projects 0 | products 0 | accomodations 0 | accomodation_categories 0
 *   tour_packages 0 | photos 0 | users.project_id 0 non-null
 *
 * `sites` replaced `projects` in 2023; the product tables were never finished and their
 * controllers were non-functional (empty stubs, wrong relation names, Roles copy-paste).
 *
 * KEPT for the vendor catalog rebuild: `product_categories`, `allowed_product_categories`.
 * `products` is dropped here and rebuilt in Phase 4.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Detach `projects` from the tables that survive it.
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_project_id_foreign');
            $table->dropColumn('project_id');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->dropForeign('photos_project_id_foreign');
            $table->dropColumn('project_id');
        });

        // 2. Drop the legacy verticals (children before parents).
        Schema::dropIfExists('products');                 // -> projects, product_categories
        Schema::dropIfExists('accomodations');            // -> projects, accomodation_categories
        Schema::dropIfExists('accomodation_categories');
        Schema::dropIfExists('tour_packages');            // -> projects

        // 3. Finally the parent.
        Schema::dropIfExists('projects');                 // -> categories, cities, users
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Irreversible: this migration retires the legacy `projects` entity and the 2022 '
            . 'product verticals, whose models and controllers are deleted in the same commit. '
            . 'Restoring the schema alone would not restore a working application — use '
            . '`git revert` on the commit instead.'
        );
    }
};
