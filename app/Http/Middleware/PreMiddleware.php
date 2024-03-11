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
        config(['user_id' => Auth::user()->id]);
        config(['app_version' => Cache::has('app_version') ?  Cache::get('app_version') : AppVersion::latest()->first()]);

        return $next($request);
    }
}
