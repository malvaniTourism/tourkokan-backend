<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventInteraction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'user_id', 'interaction_type',
        'device_type', 'platform', 'referrer', 'ip_address',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
