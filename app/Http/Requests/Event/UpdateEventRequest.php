<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEventRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'id'                    => 'required|exists:events,id',
            'site_id'               => 'sometimes|nullable|exists:sites,id',
            'event_type_id'         => 'sometimes|nullable|exists:event_types,id',
            'title'                 => 'sometimes|string|max:255',
            'description'           => 'sometimes|string|max:5000',
            'venue_name'            => 'sometimes|nullable|string|max:255',
            'address'               => 'sometimes|string',
            'taluka'                => 'sometimes|in:Devgad,Kudal,Malvan,Sawantwadi,Vengurla,Dodamarg,Kankavli,Vaibhavvadi',
            'latitude'              => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'             => 'sometimes|nullable|numeric|between:-180,180',
            'start_date'            => 'sometimes|date',
            'end_date'              => 'sometimes|date|after_or_equal:start_date',
            'start_time'            => 'sometimes|nullable|date_format:H:i:s',
            'end_time'              => 'sometimes|nullable|date_format:H:i:s',
            'banner_image'          => 'sometimes|nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'video_url'             => 'sometimes|nullable|url|max:500',
            'is_free'               => 'sometimes|boolean',
            'entry_fee'             => 'sometimes|nullable|numeric|min:0',
            'registration_required' => 'sometimes|boolean',
            'registration_link'     => 'sometimes|nullable|url',
            'registration_deadline' => 'sometimes|nullable|date',
            'max_participants'      => 'sometimes|nullable|integer|min:1',
            'tags'                  => 'sometimes|nullable|array',
            'organizer_name'        => 'sometimes|string|max:255',
            'organizer_phone'       => 'sometimes|string|max:20',
            'organizer_email'       => 'sometimes|nullable|email',
            'contact_person_name'   => 'sometimes|nullable|string|max:255',
            'contact_person_phone'  => 'sometimes|nullable|string|max:20',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json(['success' => false, 'message' => $validator->errors()], 200)
        );
    }
}
