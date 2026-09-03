<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VendorMiddleware
{
    /** Buyers need a way to reach the vendor, so both are mandatory. */
    private const REQUIRED_CONTACT = [
        'email'  => 'email address',
        'mobile' => 'mobile number',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user || !$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You need the Vendor role to perform this action. Please request the Vendor role from your profile.',
            ], 403);
        }

        $missing = $this->missingContactFields($user);

        if ($missing) {
            $labels = array_map(fn($field) => self::REQUIRED_CONTACT[$field], $missing);

            return response()->json([
                'success' => false,
                'message' => 'Add your ' . implode(' and ', $labels) . ' to your profile before using vendor features.',
                'data'    => ['missing_profile_fields' => $missing],
            ], 403);
        }

        return $next($request);
    }

    /**
     * Read the stored value, not the decrypted one. These columns are encrypted
     * and User::castAttribute returns null when a row cannot be decrypted (data
     * migrated under a different APP_KEY) — reading the cast value would lock
     * out vendors whose details are present but unreadable, which is a key
     * problem, not a missing-profile problem.
     *
     * @return array<int, string>
     */
    private function missingContactFields($user): array
    {
        $missing = [];

        foreach (array_keys(self::REQUIRED_CONTACT) as $field) {
            if (trim((string) $user->getRawOriginal($field)) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
