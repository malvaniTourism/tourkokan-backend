<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mark the places a traveller arrives at.
 *
 * The `transportation` category already has `airport`, `railway_station` and
 * `msrtc` children and all three are empty — the taxonomy was built for this
 * and never populated. So this tags the handful of sites that are genuinely
 * ways into the district, and adds no table and no category.
 *
 * Matched by exact name, and only where the site actually appears in
 * `route_stops`: a place nothing runs to cannot be arrived at. Names like
 * "Railway Cross", "Railway Gate" and "Railway Fata" are deliberately absent —
 * they are road junctions named after a railway, not stations, and a regex
 * over "Railway" would sweep all four in.
 *
 * Coordinates are left alone. These rows have none, and bus connectivity comes
 * from `route_stops` rather than from distance, so the feature works without
 * them; inventing a latitude would put a wrong number somewhere it would be
 * silently trusted. The admin panel can fill them in, which is what unlocks
 * drive-time estimates later.
 */
return new class extends Migration
{
    /** Exact site name => the category code it belongs under. */
    private const POINTS = [
        'Kankavli Railway Station' => 'railway_station',
        'Railway Station'          => 'railway_station',
        'Domestic Airport'         => 'airport',
        'Kudal St Depot'           => 'msrtc',
        'Kudal Old Bus Stand'      => 'msrtc',
        'Rajapur Bus Stand'        => 'msrtc',
    ];

    public function up(): void
    {
        $categories = DB::table('categories')
            ->whereIn('code', array_values(array_unique(self::POINTS)))
            ->pluck('id', 'code');

        foreach (self::POINTS as $name => $code) {
            $categoryId = $categories[$code] ?? null;

            if ($categoryId === null) {
                continue;
            }

            $siteIds = DB::table('sites')
                ->where('name', $name)
                ->whereIn('id', fn ($q) => $q->select('site_id')->from('route_stops'))
                ->pluck('id');

            foreach ($siteIds as $siteId) {
                $already = DB::table('category_site')
                    ->where('site_id', $siteId)
                    ->where('category_id', $categoryId)
                    ->exists();

                if (!$already) {
                    DB::table('category_site')->insert([
                        'site_id'     => $siteId,
                        'category_id' => $categoryId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $categoryIds = DB::table('categories')
            ->whereIn('code', array_values(array_unique(self::POINTS)))
            ->pluck('id');

        DB::table('category_site')
            ->whereIn('category_id', $categoryIds)
            ->whereIn('site_id', fn ($q) => $q->select('id')->from('sites')
                ->whereIn('name', array_keys(self::POINTS)))
            ->delete();
    }
};
