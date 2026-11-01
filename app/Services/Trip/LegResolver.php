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
            // Deliberately NOT filtered on `routes.status`. That column reads
            // like `sites.status` but is not: every one of the 1,389 routes
            // carries 0, the admin form defaults new ones to false, and no
            // other consumer — the landing page, the MSRTC search — looks at
            // it. Filtering on it returned no bus for any pair of places, so
            // every leg in every itinerary came back as "by road" and the one
            // thing this planner has that nothing else does was silently off.
            // If the column is ever given meaning, this is where it goes back.
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
     * Getting from where the traveller lands to where the trip starts.
     *
     * The useful question is not "is there a bus" but "is there a bus after my
     * train gets in" — an 06:10 service is no help to someone arriving at
     * 13:40. So `after` filters the departures and the soonest survivor is the
     * one worth naming.
     *
     * Returns null when nothing direct runs, which is a real answer: the plan
     * then says so rather than implying a connection the traveller would wait
     * all afternoon for.
     *
     * @return array<string,mixed>|null
     */
    public function onwardFrom(int $fromSiteId, int $toSiteId, ?string $after = null): ?array
    {
        // A wider limit than busOptions' default: the first few services of
        // the day are often useless to an afternoon arrival.
        $buses = $this->busOptions($fromSiteId, $toSiteId, 20);

        if ($buses === []) {
            return null;
        }

        if ($after === null) {
            return $buses[0];
        }

        $cutoff = substr($after, 0, 5);

        foreach ($buses as $bus) {
            // Times are HH:MM strings from the duty sheets, so a string
            // comparison orders them correctly without parsing.
            if ($bus['depart'] !== '' && $bus['depart'] >= $cutoff) {
                return $bus;
            }
        }

        // Everything today has gone. Naming tomorrow's first service is more
        // honest than naming one that has already left.
        return ['tomorrow' => true] + $buses[0];
    }

    /**
     * Which of these towns the traveller can actually reach from where they
     * land, by a direct bus. Answered before a plan is paid for.
     *
     * @param  array<int,int>  $townIds
     * @return array<int,int>  the reachable ones
     */
    public function reachableTowns(int $fromSiteId, array $townIds): array
    {
        return array_values(array_filter(
            $townIds,
            fn ($townId) => $this->busOptions($fromSiteId, (int) $townId, 1) !== []
        ));
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
