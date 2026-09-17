<?php

namespace App\Http\Middleware;

use App\Models\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PreMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $parts    = explode('/', $request->path());
        $endpoint = end($parts);

        if (!in_array($endpoint, config('urls')['non_session_url'])) {
            $user = Auth::user();

            // Store on the request object — safe under Octane (no shared singleton mutation)
            $request->attributes->set('auth_user',    $user->load(['roles']));
            $request->attributes->set('auth_user_id', $user->id);
            $request->attributes->set('language',     $user->language ?? 'en');
        }

        // Read-then-fall-back never populated the key, so this queried on every
        // request. remember() writes it; AppVersionController already forgets the
        // key on create/update, so edits still show up immediately.
        $appVersion = optional(Cache::remember(
            'app_version',
            now()->addHours(6),
            fn() => AppVersion::latest()->first()
        ))->version_number;

        $request->attributes->set('app_version', $appVersion);

        return $next($request);
    }
}
