<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two knobs the form-driven planner needs.
 *
 * `interest_parent_codes` decides which categories a traveller is offered as
 * interests. Counting live site rows alone is not enough: the heaviest codes in
 * this database are `village` (766) and `grampanchayat` (755), and nobody picks
 * "Grampanchayat" as a holiday interest. Narrowing to these parents is a
 * judgement about what is worth visiting, so it belongs in a row an admin can
 * change rather than in a list compiled into a service.
 *
 * `default_radius_km` is the search radius around each chosen town.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'key'         => 'interest_parent_codes',
            'value'       => 'kokan_view,tourist_interest,sport_activity,food,accomodation,shopping',
            'description' => 'Parent category codes whose children are offered as trip interests',
        ],
        [
            'key'         => 'default_radius_km',
            'value'       => '40',
            'description' => 'Search radius around each chosen town (km)',
        ],
        [
            'key'         => 'max_towns',
            'value'       => '3',
            'description' => 'Most towns one trip may be based in',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ROWS as $row) {
            // Insert only when missing. A key that is already there carries
            // somebody's deliberate value, and a seed must not overwrite it.
            if (DB::table('ai_configs')->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table('ai_configs')->insert(
                $row + ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('ai_configs')
            ->whereIn('key', array_column(self::ROWS, 'key'))
            ->delete();
    }
};
