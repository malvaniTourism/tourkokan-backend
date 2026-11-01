<?php

namespace App\Http\Requests\Trip;

use App\Models\AiConfig;

/**
 * The filter set, as the form submits it.
 *
 * Shared by `quote` and `plan`: the two must accept exactly the same input or
 * a quote stops predicting its own plan. `plan` extends this rather than
 * restating it.
 */
class QuoteTripRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'town_site_ids'   => 'required|array|min:1|max:' . (int) AiConfig::get('max_towns', 3),
            'town_site_ids.*' => 'integer|exists:sites,id',

            // No `exists` on the codes: a stale app sending a code that has
            // since been retired should get a thinner candidate set, not a
            // validation error it cannot explain to anyone.
            'category_codes'   => 'nullable|array|max:20',
            'category_codes.*' => 'string|max:64',

            'days'      => 'nullable|integer|min:1|max:' . (int) AiConfig::get('max_days', 7),
            'radius_km' => 'nullable|numeric|min:5|max:120',

            'stay'         => 'nullable|in:booked,suggest',
            'stay_site_id' => 'nullable|integer|exists:sites,id|required_if:stay,booked',
        ];
    }

    public function messages(): array
    {
        return [
            'town_site_ids.required'     => 'Choose a town to base your trip in.',
            'town_site_ids.max'          => 'Pick fewer towns — a trip spread any wider stops being a trip.',
            'stay_site_id.required_if'   => 'Tell us where you are staying.',
        ];
    }
}
