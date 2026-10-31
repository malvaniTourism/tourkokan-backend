<?php

namespace App\Http\Requests\Trip;

class RemoveFromCartRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'site_id' => 'required|integer',
            'trip_id' => 'required|integer|exists:saved_trips,id',
        ];
    }
}
