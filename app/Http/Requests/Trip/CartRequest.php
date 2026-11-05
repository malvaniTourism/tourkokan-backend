<?php

namespace App\Http\Requests\Trip;

use App\Models\AiConfig;
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

    /**
     * The filter fields both paths into the planner must accept identically.
     *
     * Named for the job and not for the group any more: it validates a free
     * text note too, and a list called `groupRules` that quietly decides
     * what a traveller may type is exactly how the next field added here
     * gets missed.
     *
     * Stated once and prefixed, rather than written out twice. The express
     * path validates `group.adults`; the stepper saves the same answers onto
     * the trip as `filters.group.adults`, and `TripPlanner` reads whichever
     * it is given through the same code. Two copies of this list is how one
     * of them ends up a field short — which has already happened once to
     * this feature, when `resolveFilters` rebuilt the filter array from a
     * key list that had never heard of the group.
     *
     * Everything is optional: the form defaults to two adults, and a
     * traveller who answers none of it gets the plan we were already making.
     *
     * @return array<string,string>
     */
    protected function sharedFilterRules(string $prefix = ''): array
    {
        // A ceiling of zero switches the note box off without a deploy. It
        // is the one lever worth having over a free-text field that reaches
        // a model: during an abuse spike you want a form submission, not a
        // release, which is the same argument the config endpoint itself
        // rests on.
        $maxNote = (int) AiConfig::get('max_note_chars', 500);

        return [
            $prefix . 'group'              => 'nullable|array',
            $prefix . 'group.adults'       => 'nullable|integer|min:1|max:30',
            $prefix . 'group.children'     => 'nullable|integer|min:0|max:20',
            // Bounded by childhood, because that is what the question asks.
            // The form sends the floor of a served band, never a free number.
            $prefix . 'group.youngest_age' => 'nullable|integer|min:0|max:17',

            // No `in:` on the type, for the same reason the category codes
            // carry no `exists`: a stale app sending a type that has since
            // been retired should get the ordinary pace, not a validation
            // error it cannot explain to anyone.
            $prefix . 'trip_type' => 'nullable|string|max:32',

            // Anything else they want the plan to know. Optional, bounded,
            // and treated as data the whole way down — see
            // `ItineraryPrompt::build()`, which delimits it inside the
            // payload rather than concatenating it into the instructions.
            $prefix . 'traveller_note' => $maxNote > 0
                ? 'nullable|string|max:' . $maxNote
                : 'nullable|prohibited',
        ];
    }
}
