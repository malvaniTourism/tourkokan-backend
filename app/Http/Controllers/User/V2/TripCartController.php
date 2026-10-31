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
use App\Services\Trip\TripCart;
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
    public function __construct(private TripCart $cart)
    {
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
    public function updateCart(UpdateCartRequest $request)
    {
        $trip = $this->cart->resolve(auth()->user(), $request->integer('trip_id'), create: false);

        if (!$trip) {
            return $this->sendError('Trip not found.', '', 404);
        }

        $trip->fill($request->only(['days', 'base_site_id', 'title']))->save();

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
            'base_site_id'  => $trip->base_site_id ? (int) $trip->base_site_id : null,
            'count'         => $sites->count(),
            'minimum'       => $minimum,
            // What the Plan button binds to, so the rule lives in one place.
            'can_plan'      => $sites->count() >= $minimum,
            'places'        => $this->places($sites),
        ];
    }

    private function places(Collection $sites): array
    {
        return $sites->map(fn ($site) => [
            'id'         => (int) $site->id,
            'name'       => $site->name,
            'mr_name'    => $site->mr_name,
            'image'      => $site->image,
            'logo'       => $site->logo,
            'latitude'   => $site->latitude,
            'longitude'  => $site->longitude,
            'verified'   => $site->verification_status === 'verified',
            'categories' => $site->categories->map(fn ($c) => [
                'code' => $c->code,
                'name' => $c->name,
            ])->all(),
        ])->all();
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
