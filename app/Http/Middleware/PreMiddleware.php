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
        $request_path = $request->path();
        $parts = explode('/', $request_path);
        $endpoint = end($parts);

        if (!in_array($endpoint, config('urls')['non_session_url'])) {
            $user = Auth::user();

            config([
                'user' => $user->load(['roles']),
                'user_id' => $user->id,
                'language' => $user->language
            ]);
        }

        config(['app_version' => Cache::has('app_version') ?  Cache::get('app_version')->version_number : AppVersion::latest()->first()->version_number]);
        
        return $next($request);
    }
}
