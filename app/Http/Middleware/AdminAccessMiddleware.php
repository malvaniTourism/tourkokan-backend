<?php

namespace App\Http\Middleware;

use App\Models\Roles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AdminAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $roles = Roles::select('id', 'code')->whereIn('code', ['superadmin', 'admin'])->get();
        $user = Auth::user();

        if (
            Str::startsWith($request->route()->getPrefix(), 'admin') &&
            in_array($user->role_id, array_column($roles->toArray(), 'id'))
        ) {
            return $next($request);
        }

        return response()->json(['message' => 'Access Forbidden'], 403);
    }
}
