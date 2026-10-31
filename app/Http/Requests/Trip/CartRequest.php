<?php

namespace App\Http\Requests\Trip;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared base for the cart endpoints.
 *
 * Validation failures return HTTP 200 with `{success: false, message: errors}`,
 * matching every other endpoint in this API — `assertOk()` is not evidence of
 * success here, which is why the tests check the envelope instead.
 *
 * Ownership is not checked here: `trip_id` existing is not the same as it being
 * yours, and `TripCart::resolve()` scopes every lookup to the caller.
 */
abstract class CartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json(['success' => false, 'message' => $validator->errors()], 200)
        );
    }
}
