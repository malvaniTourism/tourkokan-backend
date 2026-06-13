<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use App\Auth\EncryptedUserProvider;
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
        // Custom auth provider using blind-index hashes for encrypted email/mobile lookups
        Auth::provider('encrypted', fn($app, $config) => new EncryptedUserProvider($app['hash']));

        // Global pagination: per_page from request, default 15, hard cap 30
        Request::macro('perPage', function () {
            $perPage = (int) $this->input('per_page', config('constants.pagination.default'));
            return max(1, min($perPage, config('constants.pagination.max')));
        });

        $paginateSafe = function () {
            return $this->paginate(request()->perPage());
        };
        EloquentBuilder::macro('paginateSafe', $paginateSafe);
        QueryBuilder::macro('paginateSafe', $paginateSafe);
        Relation::macro('paginateSafe', $paginateSafe);

        // General read API: 60/min per authenticated user or IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Admin operations: 60/min per user (admins do more frequent CRUD)
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Auth routes (login/register/googleAuth): 10/min per IP — brute-force protection
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // OTP send/verify: 3/min per IP — prevents SMS cost abuse
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // Write/mutation routes (create, update, delete): 30/min per user
        RateLimiter::for('writes', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // File upload routes: 5/min per user — storage cost protection
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
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
