<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\AppVersion;
use Illuminate\Support\Facades\Cache;

class AdminAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (
            Str::startsWith($request->route()->getPrefix(), 'admin') &&
            $user->roles()->whereIn('code', ['superadmin', 'admin'])->exists()
        ) {
            config(['app_version' => Cache::has('app_version')
                ? Cache::get('app_version')->version_number
                : AppVersion::latest()->first()->version_number]);

            return $next($request);
        }

        return response()->json(['message' => 'Access Forbidden'], 403);
    }
}
