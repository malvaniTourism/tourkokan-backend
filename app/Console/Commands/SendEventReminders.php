<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventNotification;
use Illuminate\Console\Command;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';
    protected $description = 'Send 24-hour reminders to users going/interested in upcoming events';

    public function handle()
    {
        $tomorrow = now()->addDay();

        $events = Event::where('start_date', $tomorrow->toDateString())
            ->where('status', 'approved')
            ->where('reminder_sent', false)
            ->get();

        foreach ($events as $event) {
            EventNotification::create([
                'event_id'        => $event->id,
                'type'            => 'event_reminder',
                'target_audience' => 'going',
                'title'           => "Tomorrow: {$event->title}",
                'body'            => "Don't forget! {$event->title} starts tomorrow at {$event->taluka}.",
                'scheduled_at'    => now(),
                'status'          => 'pending',
            ]);

            $event->update(['reminder_sent' => true]);
        }

        $this->info("Reminders queued for {$events->count()} events.");
    }
}
