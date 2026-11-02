<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Trip\AddToCartRequest;
use App\Http\Requests\Trip\RemoveFromCartRequest;
use App\Http\Requests\Trip\ReorderCartRequest;
use App\Http\Requests\Trip\ShowCartRequest;
use App\Http\Requests\Trip\UpdateCartRequest;
use App\Models\AiConfig;
use App\Models\SavedTrip;
use App\Services\Trip\MapLinkResolver;
use App\Services\Trip\CandidateFetcher;
use App\Services\Trip\TripCart;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The cart — places a traveller has ticked while browsing.
 *
 * Useful on its own with no AI involved, which is why it ships before
 * generation exists: a saved list of places grouped into a trip is a feature
 * in itself, and it is what the planner later reads.
 */
class TripCartController extends BaseController
{
    public function __construct(
        private TripCart $cart,
        private CandidateFetcher $candidates,
    ) {
    }

    /** The open cart, or the one named by trip_id. */
    public function cart(ShowCartRequest $request)
    {
        $trip = $this->cart->resolve(auth()->user(), $request->integer('trip_id') ?: null);

        if (!$trip) {
            return $this->sendError('Trip not found.', '', 404);
        }

        return $this->sendResponse($this->present($trip), 'Cart fetched.');
    }

    /** Tick a place while browsing. */
    public function addToCart(AddToCartRequest $request)
    {
        try {
            $trip = $this->cart->add(
                auth()->user(),
                $request->integer('site_id'),
                $request->integer('trip_id') ?: null,
            );
        } catch (RuntimeException $e) {
            return $this->refusal($e);
        }

        return $this->sendResponse($this->present($trip), 'Added to your trip.');
    }

    public function removeFromCart(RemoveFromCartRequest $request)
    {
        try {
            $this->cart->remove(
                auth()->user(),
                $request->integer('trip_id'),
                $request->integer('site_id'),
            );
        } catch (RuntimeException $e) {
            return $this->refusal($e);
        }

        $trip = $this->cart->resolve(auth()->user(), $request->integer('trip_id'), create: false);

        return $this->sendResponse($this->present($trip), 'Removed from your trip.');
    }

    /** Apply the order the traveller dragged the places into. */
    public function reorderCart(ReorderCartRequest $request)
    {
        try {
            $trip = $this->cart->reorder(
                auth()->user(),
                $request->integer('trip_id'),
                $request->input('site_ids', []),
            );
        } catch (RuntimeException $e) {
            return $this->refusal($e);
        }

        return $this->sendResponse($this->present($trip), 'Order saved.');
    }

    /** Days, base town and title — the rest of the cart screen. */
    /**
     * Resolve a Google Maps link the traveller pasted.
     *
     * Read-only and spends nothing, so it sits with the rest of the cart
     * operations rather than behind the generation gates. It answers one
     * question — can we plan around this point — and when the answer is no it
     * returns the nearest places we do cover, so the refusal offers a choice.
     */
    public function resolveMapLink(Request $request, MapLinkResolver $resolver)
    {
        $request->validate([
            'url' => 'required|string|max:2048|url',
        ]);

        $result = $resolver->resolve($request->input('url'));

        if (!$result['ok']) {
            return $this->sendError(
                match ($result['reason']) {
                    'no_coordinates' => 'That link does not point at a place we can read. Use Share, then Copy link.',
                    'no_coverage'    => 'We could not check that link against our map just now.',
                    default          => 'We could not open that link. Check it and try again.',
                },
                ['reason' => $result['reason']],
                200
            );
        }

        return $this->sendResponse($result, 'Link resolved.');
    }

    /**
     * Keep a place the traveller found themselves.
     *
     * Stored on the trip's `custom_stops`, NOT as a site: it has no owner, no
     * verification and no contact details, and writing it into `sites` would
     * put an unverified point into everyone else's search results.
     */
    public function addCustomStop(Request $request)
    {
        $data = $request->validate([
            'trip_id'   => 'nullable|integer',
            'name'      => 'required|string|max:120',
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'kind'      => 'nullable|string|max:32',
        ]);

        $trip = $this->cart->resolve($request->user(), $data['trip_id'] ?? null);
        if (!$trip) {
            return $this->sendError('No trip to add that to.', [], 200);
        }

        $stops   = $trip->custom_stops ?? [];
        $stops[] = [
            'name'      => $data['name'],
            'latitude'  => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'kind'      => $data['kind'] ?? null,
            'added_at'  => now()->toIso8601String(),
        ];

        $trip->custom_stops = $stops;
        $trip->save();

        return $this->sendResponse([
            'trip_id'      => (int) $trip->id,
            'custom_stops' => $stops,
        ], 'Added to your trip.');
    }

    public function updateCart(UpdateCartRequest $request)
    {
        $trip = $this->cart->resolve(auth()->user(), $request->integer('trip_id'), create: false);

        if (!$trip) {
            return $this->sendError('Trip not found.', '', 404);
        }

        $trip->fill($request->only(['days', 'base_site_id', 'title']));

        // Merged, not replaced. The stepper saves step 1 and step 2 in
        // separate calls, so a replace would have the second wipe the first.
        if ($request->has('filters')) {
            $trip->filters = array_replace(
                (array) $trip->filters,
                array_filter((array) $request->input('filters'), fn ($v) => $v !== null)
            );
        }

        $trip->save();

        return $this->sendResponse($this->present($trip->fresh()), 'Trip updated.');
    }

    /**
     * One shape for every cart response, so the app never has to guess which
     * endpoint returned what.
     */
    private function present(SavedTrip $trip): array
    {
        $sites   = $this->cart->contents($trip);
        $minimum = (int) AiConfig::get('min_cart_places', 3);

        return [
            'trip_id'       => (int) $trip->id,
            'title'         => $trip->title,
            'status'        => $trip->status,
            'days'          => (int) $trip->days,
            // The step-1 answers, so a traveller who walks back to the first
            // step finds their towns and interests still ticked rather than an
            // empty form. Without this the stepper cannot be resumed.
            'filters'       => (array) $trip->filters,
            'base_site_id'  => $trip->base_site_id ? (int) $trip->base_site_id : null,
            'count'         => $sites->count(),
            'minimum'       => $minimum,
            // What the Plan button binds to, so the rule lives in one place.
            'can_plan'      => $sites->count() >= $minimum,
            'places'        => $this->places($sites),
        ];
    }

    /**
     * Shared with the browse endpoint, so a cart row and a pick-list row are
     * the same object to the app.
     */
    private function places(Collection $sites): array
    {
        return $this->candidates->present($sites);
    }

    private function refusal(RuntimeException $e)
    {
        return match ($e->getMessage()) {
            'not_a_destination' => $this->sendError(
                'That place cannot be added to a trip.',
                ['reason' => 'not_a_destination'],
                200
            ),
            'trip_not_found' => $this->sendError('Trip not found.', '', 404),
            default          => throw $e,
        };
    }
}
