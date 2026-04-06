<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Models\Event;
use App\Observers\EventObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Rate limiters (moved from RouteServiceProvider)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Model observers
        Event::observe(EventObserver::class);

        // Morph map for Banner polymorphic relation
        Relation::morphMap([
            'place'        => \App\Models\Place::class,
            'site'         => \App\Models\Site::class,
            'accomodation' => \App\Models\Accomodation::class,
        ]);
    }
}
