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

            // Who is going. Everything optional: the form defaults to two
            // adults and a traveller who answers nothing gets today's plan.
            'group'              => 'nullable|array',
            'group.adults'       => 'nullable|integer|min:1|max:30',
            'group.children'     => 'nullable|integer|min:0|max:20',
            'group.youngest_age' => 'nullable|integer|min:0|max:17',

            // No `in:` on the type, for the same reason the category codes
            // carry no `exists`: a stale app sending a type that has since
            // been retired should get the ordinary pace, not a validation
            // error it cannot explain to anyone.
            'trip_type' => 'nullable|string|max:32',

            'days'      => 'nullable|integer|min:1|max:' . (int) AiConfig::get('max_days', 7),
            'radius_km' => 'nullable|numeric|min:5|max:120',

            'stay'         => 'nullable|in:booked,suggest',
            'stay_site_id' => 'nullable|integer|exists:sites,id|required_if:stay,booked',

            // How they get to Kokan. `own_vehicle` needs no entry point —
            // they drive to the first stop — so site_id is required for the
            // other three and meaningless for that one.
            'arrival'         => 'nullable|array',
            'arrival.mode'    => 'required_with:arrival|in:train,flight,bus,own_vehicle',
            // required_if, not required_unless: with no arrival at all the
            // mode is null, which "is not own_vehicle", and required_unless
            // would demand a station from everyone who never answered.
            'arrival.site_id' => 'nullable|integer|exists:sites,id|required_if:arrival.mode,train,flight,bus',
            'arrival.at'      => 'nullable|date_format:H:i',
            'departure_at'    => 'nullable|date_format:H:i',
        ];
    }

    public function messages(): array
    {
        return [
            'town_site_ids.required'     => 'Choose a town to base your trip in.',
            'town_site_ids.max'          => 'Pick fewer towns — a trip spread any wider stops being a trip.',
            'stay_site_id.required_if'   => 'Tell us where you are staying.',
            'arrival.site_id.required_if' => 'Tell us where you are arriving.',
            'arrival.at.date_format'     => 'Give the arrival time as HH:MM.',
        ];
    }
}
