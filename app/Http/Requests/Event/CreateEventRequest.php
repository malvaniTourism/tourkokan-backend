<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use App\Models\Event;

class CreateEventRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'site_id'               => 'nullable|exists:sites,id',
            'event_type_id'         => 'nullable|exists:event_types,id',
            'title'                 => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $slug = Str::slug($value);
                    if (Event::where('slug', $slug)->exists()) {
                        $fail('An event with this title already exists. Please use a different title.');
                    }
                },
            ],
            'description'           => 'required|string|max:5000',
            'venue_name'            => 'nullable|string|max:255',
            'address'               => 'required|string',
            'taluka'                => 'required|in:Devgad,Kudal,Malvan,Sawantwadi,Vengurla,Dodamarg,Kankavli,Vaibhavvadi',
            'latitude'              => 'nullable|numeric|between:-90,90',
            'longitude'             => 'nullable|numeric|between:-180,180',
            'start_date'            => 'required|date|after_or_equal:today',
            'end_date'              => 'required|date|after_or_equal:start_date',
            'start_time'            => 'nullable|date_format:H:i:s',
            'end_time'              => 'nullable|date_format:H:i:s',
            'banner_image'          => 'nullable|string',
            'gallery'               => 'nullable|array',
            'video_url'             => 'nullable|url',
            'is_free'               => 'boolean',
            'entry_fee'             => 'nullable|required_if:is_free,false|numeric|min:0',
            'registration_required' => 'boolean',
            'registration_link'     => 'nullable|required_if:registration_required,true|url',
            'registration_deadline' => 'nullable|date',
            'max_participants'      => 'nullable|integer|min:1',
            'tags'                  => 'nullable|array',
            'organizer_name'        => 'nullable|string|max:255',
            'organizer_phone'       => 'nullable|string|max:20',
            'organizer_email'       => 'nullable|email',
            'contact_person_name'   => 'nullable|string|max:255',
            'contact_person_phone'  => 'nullable|string|max:20',
        ];
    }

    public function messages()
    {
        return [
            'title.required'                => 'Event title is required',
            'start_date.after_or_equal'     => 'Event cannot be in the past',
            'entry_fee.required_if'         => 'Entry fee is required for paid events',
            'registration_link.required_if' => 'Registration link is required when registration is enabled',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json(['success' => false, 'message' => $validator->errors()], 200)
        );
    }
}
