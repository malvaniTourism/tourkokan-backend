<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups near-identical bus stops under one locality, for route search only.
 *
 * "Are", "Are School", "Are Mandir", "Are Dhangarwadi" are the same village at different
 * granularity, so a "Devgad -> Are" search should find a route that stops at any of them.
 * This column carries that grouping. It is deliberately NOT `parent_id`: parent_id feeds the
 * site/city hierarchy (SiteController lists a site's children), and overloading it would make
 * these stops show up as child "places" of a bus stop. `locality` is read by nothing except
 * RouteController::routes(), so the grouping cannot leak into any other screen.
 *
 * Nullable: a stop with no locality behaves exactly as before — matched by its own id only.
 * Populate with `routes:group-localities`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('locality', 120)->nullable()->after('name');
            $table->index('locality');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['locality']);
            $table->dropColumn('locality');
        });
    }
};
