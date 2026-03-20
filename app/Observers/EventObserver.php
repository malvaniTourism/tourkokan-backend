<?php

namespace App\Observers;

use App\Models\Event;
use Illuminate\Support\Str;

class EventObserver
{
    public function creating(Event $event)
    {
        // Slug generated directly from title — uniqueness validated in CreateEventRequest
        $event->slug = Str::slug($event->title);

        // Calculate is_multi_day
        $event->is_multi_day = $event->start_date !== $event->end_date;

        // Auto-fill organizer from user profile if not provided
        if (empty($event->organizer_name) && $event->user) {
            $event->organizer_name  = $event->user->name;
            $event->organizer_phone = $event->user->mobile ?? '';
            $event->organizer_email = $event->user->email;
        }
    }

    public function updating(Event $event)
    {
        // Recalculate is_multi_day if dates changed
        if ($event->isDirty(['start_date', 'end_date'])) {
            $event->is_multi_day = $event->start_date !== $event->end_date;
        }

        // Reset to pending if major fields changed while approved
        $majorFields = ['start_date', 'end_date', 'venue_name', 'taluka', 'address'];
        if ($event->status === 'approved' && $event->isDirty($majorFields)) {
            $event->status      = 'pending';
            $event->approved_by = null;
            $event->approved_at = null;
        }
    }
}
