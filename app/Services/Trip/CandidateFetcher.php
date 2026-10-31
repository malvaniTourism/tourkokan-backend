<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The only component that queries places.
 *
 * Everything the model is ever allowed to mention passes through here first, so
 * the set of ids it can return is bounded before the call is made. Nothing else
 * in the pipeline touches `sites`.
 */
class CandidateFetcher
{
    /**
     * Places near a base point that match the traveller's interests.
     *
     * @param  array<int,string>  $categoryCodes  category codes; empty = any
     * @param  array<int,int>     $excludeIds     ids already in the cart
     */
    public function nearby(
        float $lat,
        float $lng,
        array $categoryCodes = [],
        float $radiusKm = 40,
        array $excludeIds = [],
        ?int $limit = null
    ): Collection {
        $limit ??= (int) AiConfig::get('candidate_limit', 60);

        return $this->baseQuery()
            ->when($categoryCodes !== [], fn ($q) => $q->whereHas(
                'categories',
                fn ($c) => $c->whereIn('code', $categoryCodes)
            ))
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('sites.id', $excludeIds))
            ->selectRaw(
                'sites.*, (6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(latitude))'
                . ' * cos(radians(longitude) - radians(?)) + sin(radians(?))'
                . ' * sin(radians(latitude))))) AS distance_km',
                [$lat, $lng, $lat]
            )
            ->having('distance_km', '<=', $radiusKm)
            // Verified businesses first — this is also where a paid plan's
            // priority placement will hook in, without re-architecting.
            ->orderByRaw("CASE WHEN verification_status = 'verified' THEN 0 ELSE 1 END")
            ->orderBy('distance_km')
            ->limit($limit)
            ->get();
    }

    /**
     * The traveller's own picks, loaded the same way so the model sees one
     * consistent shape. Ids not passing the destination filters are dropped:
     * a cart cannot smuggle in a bus shelter either.
     *
     * @param  array<int,int>  $siteIds
     */
    public function byIds(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }

        return $this->baseQuery()
            ->whereIn('sites.id', $siteIds)
            ->get()
            // Keep the traveller's own ordering rather than the database's.
            ->sortBy(fn ($site) => array_search($site->id, $siteIds, true))
            ->values();
    }

    /**
     * Destinations only.
     *
     * `route_stops.site_id` points at `sites`, so a large share of the rows in
     * that table are bus shelters rather than places anyone wants to visit.
     * `bus_stop_type` is the discriminator and nothing else in the codebase
     * filters on it — without this clause the planner cheerfully builds a trip
     * around bus stops.
     */
    private function baseQuery()
    {
        return Site::query()
            ->whereNull('bus_stop_type')
            ->where('sites.status', true)
            ->where('submission_status', 'approved')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['categories:id,name,code'])
            ->withAvg('rating', 'rate');
    }

    /**
     * The thin projection the model is given: ids, names, coordinates and a
     * short description. No phone numbers, no prices — those are attached
     * afterwards from the live rows, never echoed back by the model.
     */
    public function project(Collection $sites): array
    {
        return $sites->map(fn ($site) => [
            'site_id'     => (int) $site->id,
            'name'        => $site->getRawOriginal('name'),
            'categories'  => $site->categories->pluck('code')->all(),
            'lat'         => (float) $site->latitude,
            'lng'         => (float) $site->longitude,
            'about'       => \Illuminate\Support\Str::limit(strip_tags((string) $site->description), 160),
        ])->all();
    }
}
