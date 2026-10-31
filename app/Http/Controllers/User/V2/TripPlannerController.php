<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;

/**
 * Trip planner — traveller-facing endpoints.
 *
 * Phase 0 scaffold. The pipeline behind these (CandidateFetcher, LegResolver,
 * the assembler and TripPlanner) is built and covered by
 * `TripPlannerPipelineTest`, but nothing is exposed over HTTP yet: generation
 * must not be reachable before the verified-email gate, the credit spend and
 * `throttle:trip` exist, or it is an uncapped spend endpoint.
 *
 * Method names here match the contract in the implementation plan, so Phase 2
 * fills bodies rather than renaming routes.
 */
class TripPlannerController extends BaseController
{
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

    /** Generate from the places the traveller ticked. The primary flow. */
    public function planFromCart(Request $request)
    {
        return $this->notYet('Plan from cart');
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
