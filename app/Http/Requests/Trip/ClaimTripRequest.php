<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

class ClaimTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The token the traveller was browsing under. It is the only
            // proof that the cart being claimed was theirs — trip ids are
            // sequential, so an id on its own would let anyone claim anyone's.
            'guest_token' => 'required|string|max:4096',
        ];
    }
}
