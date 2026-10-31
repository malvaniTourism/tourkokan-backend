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
        return Favourite::where('trip_id', $trip->id)
            ->where('favouritable_type', (new Site())->getMorphClass());
    }

    private function nextSortOrder(SavedTrip $trip): int
    {
        return (int) $this->rows($trip)->max('sort_order') + 1;
    }
}
