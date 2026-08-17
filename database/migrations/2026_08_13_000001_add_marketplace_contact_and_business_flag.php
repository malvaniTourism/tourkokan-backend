<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two marketplace asks from the app team (docs/marketplace-backend-asks.md):
 *
 *   #2  Public contact — a business needs a phone and (optionally) a WhatsApp number that
 *       buyers can call/message. The owner's own phone is encrypted PII and stays private;
 *       this is the business's public line. lat/lng already exist on sites.
 *
 *   #4  Vendor-registrable categories — the "Register a business" picker must hide
 *       directory-only branches (Destination, Emergency, Government…). A category is
 *       registrable iff it (or, for a parent, its children) can carry products, i.e. it has
 *       a row in allowed_product_categories. Stored as a flag so the app filters on one
 *       field instead of deriving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('domain_name');
            $table->string('whatsapp', 20)->nullable()->after('phone');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_business')->default(false)->after('is_hot_category')->index();
        });

        // A category is registrable if it appears in the whitelist, and a parent is
        // registrable if any of its children are — so the picker can show the branch.
        $leafIds = DB::table('allowed_product_categories')->distinct()->pluck('category_id');

        DB::table('categories')->whereIn('id', $leafIds)->update(['is_business' => true]);

        $parentIds = DB::table('categories')->whereIn('id', $leafIds)
            ->whereNotNull('parent_id')->distinct()->pluck('parent_id');

        DB::table('categories')->whereIn('id', $parentIds)->update(['is_business' => true]);
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['phone', 'whatsapp']));
        Schema::table('categories', function (Blueprint $t) {
            $t->dropIndex(['is_business']);
            $t->dropColumn('is_business');
        });
    }
};
