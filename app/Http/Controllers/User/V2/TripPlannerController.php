<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Trip\PlanFromCartRequest;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\TripCart;
use App\Services\Trip\TripPlanner;
use Illuminate\Http\Request;

/**
 * Trip planner — traveller-facing endpoints.
 *
 * `planFromCart` is live, behind three things that must all be in place before
 * generation is reachable at all: `verified.email` (a guest holds a working
 * JWT, so `auth:api` alone is not an identity), the credit spend, and
 * `throttle:trip`. Without them this is an uncapped spend endpoint.
 *
 * The form-driven `plan`, `quote`, `questionSet` and the trip listings are
 * still stubs. Their names match the contract in the implementation plan, so
 * filling them does not rename a route.
 */
class TripPlannerController extends BaseController
{
    public function __construct(
        private TripPlanner $planner,
        private TripCart $cart,
        private CreditLedger $credits,
    ) {
    }

    /** Filter options for the plan form, built from the live category codes. */
    public function questionSet(Request $request)
    {
        return $this->notYet('Question set');
    }

    /** Candidate count and credit cost for a filter set. Free, no model call. */
    public function quote(Request $request)
    {
        return $this->notYet('Quote');
    }

    /** Generate from the form's filters. Spends one credit. */
    public function plan(Request $request)
    {
        return $this->notYet('Plan');
    }

    /**
     * Generate from the places the traveller ticked. The primary flow.
     *
     * Reachable only behind `verified.email` and `throttle:trip` — a guest
     * holds a working JWT, so `auth:api` alone would make this an uncapped
     * spend endpoint.
     */
    public function planFromCart(PlanFromCartRequest $request)
    {
        $user = auth()->user();

        // The free plans a verified account starts with. Idempotent, and
        // granted here rather than at signup so an account that verified
        // before this feature existed still gets them.
        $this->credits->grantSignupCredits($user);

        $trip = $this->cart->resolve($user, $request->integer('trip_id'), create: false);

        if (!$trip) {
            return $this->sendError('Trip not found.', '', 404);
        }

        $result = $this->planner->planFromCart($trip);

        if (!$result['ok']) {
            return $this->sendError(
                $this->message($result['reason']),
                [
                    'reason'  => $result['reason'],
                    'credits' => $this->credits->balance($user),
                ],
                200
            );
        }

        return $this->sendResponse([
            'trip_id'   => (int) $trip->id,
            'itinerary' => $result['itinerary'],
            'credits'   => $this->credits->balance($user),
        ], 'Your trip is ready.');
    }

    /** Copy the app shows for each refusal. */
    private function message(?string $reason): string
    {
        return match ($reason) {
            'cart_too_small'       => 'Add at least three places so we can spread them across your days.',
            'insufficient_credits' => 'You have used all your free plans. Invite a friend to get more.',
            'generation_failed'    => 'We could not build a full plan from these places. Nothing was charged — please try again.',
            default                => 'We could not plan this trip.',
        };
    }

    /** The traveller's saved trips. */
    public function myTrips(Request $request)
    {
        return $this->notYet('My trips');
    }

    /** One saved itinerary. */
    public function getTrip(Request $request)
    {
        return $this->notYet('Get trip');
    }

    private function notYet(string $feature)
    {
        return $this->sendError(
            "{$feature} is not implemented yet (Phase 0 scaffold).",
            [],
            501
        );
    }
}
