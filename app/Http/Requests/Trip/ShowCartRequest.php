<?php

namespace App\Http\Requests\Trip;

class ShowCartRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'trip_id' => 'nullable|integer|exists:saved_trips,id',
            'days'    => 'nullable|integer|min:1|max:14',
        ];
    }
}
