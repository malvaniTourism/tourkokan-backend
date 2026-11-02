<?php

namespace App\Http\Requests\Trip;

use App\Models\AiConfig;

/**
 * Step 2 of the stepper: the places a traveller may pick from.
 *
 * A deliberate trim of `QuoteTripRequest` — the same towns and the same
 * category codes, without the days, stay and arrival answers that only the
 * planner needs. Keeping the two in step matters: browse has to offer exactly
 * what the planner would consider, or someone picks a place the plan then
 * refuses to build around.
 */
class BrowsePlacesRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'town_site_ids'   => 'required|array|min:1|max:' . (int) AiConfig::get('max_towns', 3),
            'town_site_ids.*' => 'integer|exists:sites,id',

            // No `exists` on the codes, as in QuoteTripRequest: a stale app
            // sending a retired code should get a thinner list, not an error
            // it cannot explain to anyone.
            'category_codes'   => 'nullable|array|max:20',
            'category_codes.*' => 'string|max:64',

            'radius_km' => 'nullable|numeric|min:5|max:120',

            // Narrowing by name, for a long list. Matched against the stored
            // name only — the descriptions are long and would make a search
            // for "beach" return everything that mentions one.
            'search' => 'nullable|string|max:80',
        ];
    }

    public function messages(): array
    {
        return [
            'town_site_ids.required' => 'Choose a town first.',
            'town_site_ids.max'      => 'Pick fewer towns — a trip spread any wider stops being a trip.',
        ];
    }
}
