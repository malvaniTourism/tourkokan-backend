<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('events:send-reminders')->hourly();
Schedule::command('events:mark-completed')->dailyAt('00:30');

// Product engagement metering. The rollup must run before the prune, or the prune refuses
// to discard raw events it cannot see an aggregate for.
// See docs/VENDOR_PRODUCTS_DESIGN.md §9.
Schedule::command('products:rollup-stats')->dailyAt('00:45');
Schedule::command('products:prune-view-events')->weeklyOn(1, '02:00');
