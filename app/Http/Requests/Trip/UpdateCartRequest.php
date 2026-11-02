<?php

namespace App\Http\Requests\Trip;

class UpdateCartRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'trip_id'      => 'required|integer|exists:saved_trips,id',
            'days'         => 'nullable|integer|min:1|max:14',
            'base_site_id' => 'nullable|integer|exists:sites,id',
            'title'        => 'nullable|string|max:120',

            // The stepper's step-1 answers, kept on the trip so planFromCart
            // can honour them. Validated to the same shape the quote accepts,
            // because they end up in the same place and are read by the same
            // code — a looser rule here would let the stepper store something
            // the planner cannot use.
            'filters'                   => 'nullable|array',
            'filters.town_site_ids'     => 'nullable|array|max:' . (int) \App\Models\AiConfig::get('max_towns', 3),
            'filters.town_site_ids.*'   => 'integer|exists:sites,id',
            'filters.category_codes'    => 'nullable|array|max:20',
            'filters.category_codes.*'  => 'string|max:64',
            'filters.radius_km'         => 'nullable|numeric|min:5|max:120',
            'filters.stay'              => 'nullable|in:booked,suggest',
            'filters.stay_site_id'      => 'nullable|integer|exists:sites,id',
            'filters.arrival'           => 'nullable|array',
            'filters.arrival.mode'      => 'nullable|in:train,flight,bus,own_vehicle',
            'filters.arrival.site_id'   => 'nullable|integer|exists:sites,id',
            'filters.arrival.at'        => 'nullable|date_format:H:i',
            'filters.departure_at'      => 'nullable|date_format:H:i',
        ];
    }

    public function messages(): array
    {
        return [
            'days.max' => 'We plan trips of up to 14 days.',
        ];
    }
}
