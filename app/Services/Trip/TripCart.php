<?php

namespace App\Services\Trip;

use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The cart: `favourites` rows that share a `trip_id`.
 *
 * There is no cart table. A favourite with a null `trip_id` is an ordinary
 * favourite exactly as before; one stamped with a trip is in that trip's cart.
 * The trip itself is a `saved_trips` row in `draft` until an itinerary is
 * written against it.
 */
class TripCart
{
    public function __construct(private CandidateFetcher $candidates)
    {
    }

    /**
     * The trip a traveller is currently filling. Several drafts may exist —
     * someone planning two holidays is not an error — so the most recently
     * touched one is the open cart unless a specific trip is named.
     */
    public function openDraft(User $user, bool $create = true): ?SavedTrip
    {
        $trip = SavedTrip::where('user_id', $user->id)
            ->drafts()
            ->latest('updated_at')
            ->first();

        if ($trip || !$create) {
            return $trip;
        }

        return SavedTrip::create([
            'user_id' => $user->id,
            'status'  => 'draft',
            'days'    => 2,
        ]);
    }

    /**
     * Resolve a trip the caller named, or the open draft. Returns null when the
     * id belongs to someone else — a cart is never readable across accounts.
     */
    public function resolve(User $user, ?int $tripId, bool $create = true): ?SavedTrip
    {
        if ($tripId === null) {
            return $this->openDraft($user, $create);
        }

        return SavedTrip::where('id', $tripId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Add a place. Idempotent — ticking a place twice leaves one row.
     *
     * @throws \RuntimeException when the site is not a valid destination
     */
    public function add(User $user, int $siteId, ?int $tripId = null): SavedTrip
    {
        // A bus shelter or an unapproved listing must not enter a cart either.
        // CandidateFetcher is the single definition of "somewhere you can go".
        if ($this->candidates->byIds([$siteId])->isEmpty()) {
            throw new \RuntimeException('not_a_destination');
        }

        $trip = $this->resolve($user, $tripId);

        if (!$trip) {
            throw new \RuntimeException('trip_not_found');
        }

        $existing = $this->rows($trip)->where('favouritable_id', $siteId)->first();

        if (!$existing) {
            Favourite::create([
                'user_id'           => $user->id,
                'trip_id'           => $trip->id,
                'sort_order'        => $this->nextSortOrder($trip),
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => $siteId,
            ]);

            $trip->touch();
        }

        return $trip->fresh();
    }

    /** Remove a place. Returns how many are left. */
    public function remove(User $user, int $tripId, int $siteId): int
    {
        $trip = $this->resolve($user, $tripId, create: false);

        if (!$trip) {
            throw new \RuntimeException('trip_not_found');
        }

        $this->rows($trip)->where('favouritable_id', $siteId)->delete();
        $trip->touch();

        return $this->rows($trip)->count();
    }

    /**
     * The places a traveller pasted a map link for, as Site models that were
     * never saved.
     *
     * Lives here rather than in the planner because two callers need the same
     * answer: the planner, which plans them, and the cart presenter, which has
     * to show them. Two definitions of the id convention would be two
     * definitions of "ours or theirs".
     *
     * They get **negative** ids — an id space `sites` can never produce, which
     * makes the test a sign check rather than a lookup that could one day
     * collide. An unverified pin must never become a row in `sites`: it has no
     * owner and no approval, and it would leak into everyone else's search.
     *
     * `syncOriginal()` matters: `project()` reads names through
     * `getRawOriginal()`, which is empty on a model that was never loaded.
     *
     * @return Collection<int,Site>
     */
    public function customSites(?SavedTrip $trip): Collection
    {
        return collect($trip?->custom_stops ?? [])
            ->filter(fn ($s) => isset($s['latitude'], $s['longitude']))
            ->values()
            ->map(function ($stop, $i) {
                $site = new Site([
                    'name'      => (string) ($stop['name'] ?? 'Your place'),
                    'latitude'  => (float) $stop['latitude'],
                    'longitude' => (float) $stop['longitude'],
                ]);
                $site->id = -1 - $i;   // -1, -2, … in the order they were added
                $site->syncOriginal();
                // Nothing to load and nothing to show: without this, present()
                // fires a categories query per pin against a row that is not
                // there.
                $site->setRelation('categories', collect());
                // Carried so the app can ask for this exact pin back out.
                // NOT the negative id: that renumbers whenever the list changes.
                $site->custom_key = $stop['key'] ?? ($stop['added_at'] ?? null);

                return $site;
            });
    }

    /**
     * Take a pasted pin off the trip, by the key it was stored with.
     *
     * Keyed rather than positional on purpose. `customSites()` filters out
     * coordinate-less entries BEFORE numbering, so the negative id the app
     * holds is an index into the filtered list, not into `custom_stops` — and
     * both renumber on every add. Removing by position can delete a different
     * place than the one on screen.
     *
     * Idempotent: a key already gone returns the trip untouched rather than an
     * error, the same habit `reorder()` has, because a stale client should not
     * be shown a failure for work that is already done.
     */
    public function removeCustomStop(SavedTrip $trip, string $key): SavedTrip
    {
        $kept    = [];
        $removed = false;

        foreach ((array) $trip->custom_stops as $stop) {
            $matches = isset($stop['key'])
                ? $stop['key'] === $key
                : ($stop['added_at'] ?? null) === $key;   // written before keys existed

            if (!$removed && $matches) {
                $removed = true;
                continue;
            }

            $kept[] = $stop;
        }

        if ($removed) {
            $trip->custom_stops = $kept;
            $trip->save();
        }

        return $trip->fresh();
    }

    /** How many pasted pins this trip holds. */
    public function customCount(?SavedTrip $trip): int
    {
        return $this->customSites($trip)->count();
    }

    /**
     * Apply a traveller's ordering. Ids not in the cart are ignored rather
     * than erroring — a stale client should not break a reorder.
     *
     * @param  array<int,int>  $siteIds
     */
    public function reorder(User $user, int $tripId, array $siteIds): SavedTrip
    {
        $trip = $this->resolve($user, $tripId, create: false);

        if (!$trip) {
            throw new \RuntimeException('trip_not_found');
        }

        $position = 0;
        foreach ($siteIds as $siteId) {
            $this->rows($trip)
                ->where('favouritable_id', (int) $siteId)
                ->update(['sort_order' => $position++]);
        }

        $trip->touch();

        return $trip->fresh();
    }

    /**
     * The places in the cart, in the traveller's order.
     *
     * @return Collection<int,Site>
     */
    public function contents(SavedTrip $trip): Collection
    {
        $ids = $this->rows($trip)
            ->orderByRaw('sort_order IS NULL, sort_order')
            ->orderBy('id')
            ->pluck('favouritable_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $this->candidates->byIds($ids);
    }

    public function count(SavedTrip $trip): int
    {
        return $this->rows($trip)->count();
    }

    /** Favourite rows belonging to this trip, sites only. */
    private function rows(SavedTrip $trip)
    {
        // inTrip() lifts the global scope that hides cart rows from every
        // ordinary favourites query.
        return Favourite::inTrip($trip->id)
            ->where('favouritable_type', (new Site())->getMorphClass());
    }

    private function nextSortOrder(SavedTrip $trip): int
    {
        return (int) $this->rows($trip)->max('sort_order') + 1;
    }
}
