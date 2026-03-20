<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventNotification extends Model
{
    protected $fillable = [
        'event_id', 'type', 'target_audience', 'target_taluka', 'target_category',
        'title', 'body', 'image_url', 'action_url',
        'scheduled_at', 'sent_at', 'status',
        'sent_count', 'delivered_count', 'opened_count', 'clicked_count',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
