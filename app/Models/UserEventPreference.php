<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEventPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notify_new_events', 'notify_event_reminders', 'notify_event_updates',
        'preferred_talukas', 'preferred_event_types',
        'email_notifications', 'push_notifications', 'sms_notifications',
    ];

    protected $casts = [
        'preferred_talukas'       => 'array',
        'preferred_event_types'   => 'array',
        'notify_new_events'       => 'boolean',
        'notify_event_reminders'  => 'boolean',
        'notify_event_updates'    => 'boolean',
        'email_notifications'     => 'boolean',
        'push_notifications'      => 'boolean',
        'sms_notifications'       => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
