<?php

namespace App\Http\Requests\Trip;

/**
 * Generating from the form. The same filters as a quote, plus an optional name
 * for the trip.
 */
class PlanTripRequest extends QuoteTripRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'title' => 'nullable|string|max:120',
        ];
    }
}
