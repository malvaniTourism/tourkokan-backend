<?php

namespace App\Http\Requests\Trip;

class PlanFromCartRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'trip_id' => 'required|integer|exists:saved_trips,id',
        ];
    }

    public function messages(): array
    {
        return [
            'trip_id.required' => 'Tell us which trip to plan.',
        ];
    }
}
