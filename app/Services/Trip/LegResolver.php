<?php

namespace App\Services\Trip;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Travel between two places, computed in PHP from our own tables.
 *
 * The model is told what connections exist; it never works one out. A bus time
 * in a finished itinerary is one the MSRTC duty sheets support, or it is absent.
 *
 * `route_stops.site_id` points at `sites`, so a place and a bus stop are the
 * same kind of row — a leg is found by joining the table to itself on route_id
 * and keeping the pairs whose serial numbers run in the right direction.
 */
class LegResolver
{
    /** Straight-line kilometres between two sites. */
    public function distanceKm(Site $from, Site $to): float
    {
        $earthKm = 6371;

        $lat1 = deg2rad((float) $from->latitude);
        $lat2 = deg2rad((float) $to->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $to->longitude - (float) $from->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round($earthKm * 2 * asin(min(1, sqrt($a))), 1);
    }

    /**
     * Direct bus services from one site to another, soonest first.
     *
     * Only direct routes for now. A one-change journey is a later addition and
     * belongs here rather than anywhere upstream.
     *
     * @return array<int,array<string,mixed>>
     */
    public function busOptions(int $fromSiteId, int $toSiteId, int $limit = 3): array
    {
        $rows = DB::table('route_stops as a')
            ->join('route_stops as b', function ($join) {
                $join->on('a.route_id', '=', 'b.route_id')
                    ->whereColumn('a.serial_no', '<', 'b.serial_no');
            })
            ->join('routes', 'routes.id', '=', 'a.route_id')
            ->where('a.site_id', $fromSiteId)
            ->where('b.site_id', $toSiteId)
            ->where('routes.status', true)
            ->orderBy('a.dept_time')
            ->limit($limit)
            ->get([
                'routes.id as route_id',
                'routes.route_no',
                'routes.name as route_name',
                'routes.working_days',
                'a.dept_time as depart',
                'b.arr_time as arrive',
            ]);

        return $rows->map(fn ($r) => [
            'route_no'     => $r->route_no,
            'route_name'   => $r->route_name,
            'depart'       => substr((string) $r->depart, 0, 5),
            'arrive'       => substr((string) $r->arrive, 0, 5),
            // A blank frequency means nobody recorded it, not that no bus runs.
            'working_days' => $r->working_days !== null && $r->working_days !== ''
                ? $r->working_days
                : null,
        ])->all();
    }

    /**
     * Every leg between consecutive places, as facts for the prompt.
     *
     * @param  \Illuminate\Support\Collection<int,Site>  $sites
     * @return array<int,array<string,mixed>>
     */
    public function legsFor($sites): array
    {
        $legs  = [];
        $items = $sites->values();

        for ($i = 0; $i < $items->count() - 1; $i++) {
            $from = $items[$i];
            $to   = $items[$i + 1];

            $buses = $this->busOptions((int) $from->id, (int) $to->id);

            $legs[] = [
                'from_site_id' => (int) $from->id,
                'to_site_id'   => (int) $to->id,
                'distance_km'  => $this->distanceKm($from, $to),
                'bus'          => $buses,
                'mode'         => $buses !== [] ? 'bus' : 'road',
            ];
        }

        return $legs;
    }
}
