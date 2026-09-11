<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records where a user signed up — the Android app or the web.
 *
 * Stored on the user itself rather than inferred from `user_activity_logs`, so the fact is
 * permanent (log retention cannot erase it), needs no join, and cannot be re-derived wrongly
 * later. Nullable because every existing row pre-dates the column; a null means "unknown /
 * before tracking", which reporting should treat separately from a real answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registered_from', 20)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('registered_from');
        });
    }
};
