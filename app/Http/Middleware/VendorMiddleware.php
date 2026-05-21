<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VendorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user || !$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You need the Vendor role to perform this action. Please request the Vendor role from your profile.',
            ], 403);
        }

        return $next($request);
    }
}
