<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (
            Str::startsWith($request->route()->getPrefix(), 'admin') &&
            $user->roles()->whereIn('code', ['superadmin', 'admin'])->exists()
        ) {
            return $next($request);
        }

        return response()->json(['message' => 'Access Forbidden'], 403);
    }
}
