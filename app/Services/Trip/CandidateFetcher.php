<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\Category;
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
     * Candidates around several towns at once, for a multi-town trip.
     *
     * Each town is searched in turn and the results merged, rather than taking
     * one radius big enough to cover them all — a 90km circle drawn around
     * Kudal would reach half the district and the nearest places would crowd
     * out everything near the second town.
     *
     * @param  Collection<int,Site>  $anchors
     * @param  array<int,string>     $categoryCodes
     * @param  array<int,int>        $excludeIds
     */
    public function aroundMany(
        Collection $anchors,
        array $categoryCodes = [],
        float $radiusKm = 40,
        array $excludeIds = []
    ): Collection {
        $total = (int) AiConfig::get('candidate_limit', 60);

        if ($anchors->isEmpty()) {
            return collect();
        }

        // An even share each, so a three-town trip does not arrive as one town
        // with two footnotes.
        $share = max(1, (int) ceil($total / $anchors->count()));
        $found = collect();

        foreach ($anchors as $anchor) {
            if ($anchor->latitude === null || $anchor->longitude === null) {
                continue;
            }

            $found = $found->concat($this->nearby(
                (float) $anchor->latitude,
                (float) $anchor->longitude,
                $categoryCodes,
                $radiusKm,
                array_merge($excludeIds, $found->pluck('id')->all()),
                $share,
            ));
        }

        return $found->take($total)->values();
    }

    /**
     * How many places a filter set would actually offer. The honest answer to
     * "can this trip be planned", and free — no model call, no credit.
     *
     * @param  Collection<int,Site>  $anchors
     * @param  array<int,string>     $categoryCodes
     */
    public function countAround(
        Collection $anchors,
        array $categoryCodes = [],
        float $radiusKm = 40
    ): int {
        return $this->aroundMany($anchors, $categoryCodes, $radiusKm)->count();
    }

    /**
     * The towns a trip can be based in: the `destination` sites that carry
     * coordinates, which is what the Haversine search needs.
     *
     * The `cities` table is not used — it is empty and nothing links sites to
     * it. A town here is an ordinary site tagged `city`, `district` or
     * `village`, which is how the rest of the app already models them.
     *
     * @param  array<int,string>  $codes
     */
    public function towns(array $codes = ['city', 'district']): Collection
    {
        return $this->baseQuery()
            ->whereHas('categories', fn ($c) => $c->whereIn('code', $codes))
            ->orderBy('name')
            ->get();
    }

    /**
     * Interests worth offering, with the number of places behind each.
     *
     * Counted live rather than listed in code, because a filter nobody can
     * satisfy is worse than a missing one: offer "Water Sport" where no site
     * carries it, the traveller picks it, and the refusal arrives after they
     * have chosen. A code only appears here once there is somewhere to send
     * them.
     *
     * Counts alone are not enough, though — the two heaviest codes in this
     * database are `village` and `grampanchayat`. Which parents count as
     * interests is a judgement, so it lives in `ai_configs`.
     *
     * @return array<int,array{code: string, name: string, mr_name: string|null, icon: string|null, places: int}>
     */
    public function interestOptions(int $minPlaces = 1): array
    {
        $parents = array_filter(array_map(
            'trim',
            explode(',', (string) AiConfig::get(
                'interest_parent_codes',
                'kokan_view,tourist_interest,sport_activity,food,accomodation,shopping'
            ))
        ));

        return Category::query()
            ->where('status', true)
            ->whereIn(
                'parent_id',
                Category::query()->whereIn('code', $parents)->select('id')
            )
            // The same definition of "somewhere you can go" the planner uses,
            // so a count here is a count the pipeline will honour.
            ->withCount(['sites as places' => fn ($q) => $this->destinations($q)])
            ->having('places', '>=', $minPlaces)
            ->orderByDesc('places')
            ->get(['id', 'code', 'name', 'mr_name', 'icon'])
            ->map(fn (Category $c) => [
                'code'    => $c->code,
                'name'    => $c->name,
                'mr_name' => $c->mr_name,
                'icon'    => $c->icon,
                'places'  => (int) $c->places,
            ])
            ->all();
    }

    /**
     * The leaf codes under a parent. Sites attach to leaves, never to the
     * parent, so "somewhere to stay" has to be expanded before it can filter.
     *
     * @return array<int,string>
     */
    public function childCodes(string $parentCode): array
    {
        return Category::query()
            ->where('status', true)
            ->whereIn(
                'parent_id',
                Category::query()->where('code', $parentCode)->select('id')
            )
            ->pluck('code')
            ->all();
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
        return $this->destinations(Site::query())
            ->with(['categories:id,name,code'])
            ->withAvg('rating', 'rate');
    }

    /**
     * The predicate on its own, so a count subquery can reuse it without
     * dragging the eager loads and the rating average along.
     */
    private function destinations($query)
    {
        return $query
            ->whereNull('bus_stop_type')
            ->where('sites.status', true)
            ->where('submission_status', 'approved')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
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
