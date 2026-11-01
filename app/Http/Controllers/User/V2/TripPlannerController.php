<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Trip\PlanFromCartRequest;
use App\Http\Requests\Trip\PlanTripRequest;
use App\Http\Requests\Trip\QuoteTripRequest;
use App\Models\AiConfig;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\CandidateFetcher;
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
 * `questionSet` and `quote` are free and spend nothing, so they sit behind
 * `auth:api` alone. `plan` spends a credit and therefore carries the same
 * guards as `planFromCart`.
 *
 * The trip listings are still stubs. Their names match the contract in the
 * implementation plan, so filling them does not rename a route.
 */
class TripPlannerController extends BaseController
{
    public function __construct(
        private TripPlanner $planner,
        private TripCart $cart,
        private CreditLedger $credits,
        private CandidateFetcher $candidates,
    ) {
    }

    /**
     * Everything the plan form needs to render itself.
     *
     * Served rather than compiled into the app because all of it moves: a new
     * town, a category that just gained its first place, a changed free quota.
     * An app that shipped its own copy would offer a filter the database
     * cannot satisfy until the next release.
     */
    public function questionSet(Request $request)
    {
        $user = auth()->user();

        return $this->sendResponse([
            'towns' => $this->candidates->towns()->map(fn ($site) => [
                'site_id' => (int) $site->id,
                'name'    => $site->name,
                'mr_name' => $site->mr_name,
                'lat'     => (float) $site->latitude,
                'lng'     => (float) $site->longitude,
            ])->all(),
            // Only codes with places behind them, newest counts each time.
            'interests' => $this->candidates->interestOptions(),
            'days'      => [
                'min'     => 1,
                'max'     => (int) AiConfig::get('max_days', 7),
                'default' => 2,
            ],
            'max_towns' => (int) AiConfig::get('max_towns', 3),
            'radius_km' => (float) AiConfig::get('default_radius_km', 40),
            'credits'   => $this->credits->balance($user),
        ], 'Plan form.');
    }

    /**
     * What a filter set would give you, before anything is spent.
     *
     * Free and model-free: the form calls this as the traveller changes their
     * mind, so they learn that two days of waterfalls alone will not fill
     * while they can still add an interest — not after a credit is gone.
     */
    public function quote(QuoteTripRequest $request)
    {
        $quote = $this->planner->quote($request->validated());

        return $this->sendResponse([
            'can_plan'   => $quote['ok'],
            'reason'     => $quote['reason'],
            'candidates' => $quote['candidates'],
            'needed'     => $quote['needed'],
            'days'       => $quote['days'],
            'towns'      => $quote['towns'],
            'cost'       => 1,
            'credits'    => $this->credits->balance(auth()->user()),
        ], $quote['ok'] ? 'Ready to plan.' : $this->message($quote['reason']));
    }

    /**
     * Generate from the form's filters. Spends one credit.
     *
     * Returns the same payload as `planFromCart` — `{trip_id, itinerary,
     * credits}` — so the app's itinerary screen serves both entry points
     * without knowing which one it came from.
     */
    public function plan(PlanTripRequest $request)
    {
        $user = auth()->user();

        $this->credits->grantSignupCredits($user);

        $result = $this->planner->planFromForm($user, $request->validated());

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
            'trip_id'   => $result['trip_id'],
            'itinerary' => $result['itinerary'],
            'credits'   => $this->credits->balance($user),
        ], 'Your trip is ready.');
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
            'town_not_found'       => 'Choose a town to base your trip in.',
            'not_enough_places'    => 'There is not enough to see for a trip this long. Add an interest, widen the area, or shorten the trip.',
            // Deliberately not "we are over budget". Trips already planned
            // still work, so the useful advice is to try a popular area.
            'spend_ceiling'        => 'Trip planning is busy right now. Popular areas still work — try again in a little while.',
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
