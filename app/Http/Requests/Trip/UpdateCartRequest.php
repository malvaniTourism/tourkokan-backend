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
        ];
    }

    public function messages(): array
    {
        return [
            'days.max' => 'We plan trips of up to 14 days.',
        ];
    }
}
