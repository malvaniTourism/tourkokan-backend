<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class MarkCompletedEvents extends Command
{
    protected $signature = 'events:mark-completed';
    protected $description = 'Mark approved events as completed once their end date has passed';

    public function handle()
    {
        $count = Event::where('status', 'approved')
            ->where('end_date', '<', now()->toDateString())
            ->update(['status' => 'completed']);

        $this->info("Marked {$count} events as completed.");
    }
}
