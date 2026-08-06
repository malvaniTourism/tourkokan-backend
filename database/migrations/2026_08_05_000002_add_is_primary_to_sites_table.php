<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a vendor's primary business location.
 *
 * A vendor's outlets are grouped solely by `sites.user_id` — there is no separate vendor
 * entity. This flag distinguishes the head/primary location from its branches.
 *
 * `parent_id` is NOT used for this: it is the geographic tree
 * (District > City/Village > Place) and every branch must keep its true geographic parent,
 * otherwise the branch disappears from its own village listing and from nearby-search.
 *
 * At most one primary per user — enforced in application logic (SiteController::setPrimarySite),
 * since MySQL has no partial unique index.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §2.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('user_id');
            $table->index(['user_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_primary']);
            $table->dropColumn('is_primary');
        });
    }
};
