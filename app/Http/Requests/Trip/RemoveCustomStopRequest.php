<?php

namespace App\Http\Requests\Trip;

/**
 * Take one pasted pin off a trip.
 *
 * The key is the uuid written when the pin was added, not the negative id the
 * app draws the row with: that id is a position in a filtered list and
 * renumbers whenever the list changes. `max:64` rather than a uuid rule
 * because pins added before keys existed are matched on their `added_at`
 * timestamp instead.
 *
 * Ownership is deliberately not checked here — `TripCart::resolve()` scopes
 * every lookup to the caller, as CartRequest's docblock explains.
 */
class RemoveCustomStopRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            'trip_id' => 'required|integer|exists:saved_trips,id',
            'key'     => 'required|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'Tell us which place to take out.',
        ];
    }
}
