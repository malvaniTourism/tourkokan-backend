<?php

namespace App\Http\Requests\Trip;

class AddToCartRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'site_id' => 'required|integer|exists:sites,id',
            // Absent means "my open cart" — the common case while browsing.
            'trip_id' => 'nullable|integer|exists:saved_trips,id',
        ];
    }

    public function messages(): array
    {
        return [
            'site_id.required' => 'Tell us which place to add.',
            'site_id.exists'   => 'That place is no longer listed.',
        ];
    }
}
