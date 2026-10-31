<?php

namespace App\Http\Requests\Trip;

class ReorderCartRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'trip_id'    => 'required|integer|exists:saved_trips,id',
            'site_ids'   => 'required|array|min:1|max:60',
            'site_ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'site_ids.required' => 'Send the places in the order you want them.',
        ];
    }
}
