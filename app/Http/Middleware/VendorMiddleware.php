<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VendorMiddleware
{
    /** Buyers need a way to reach the vendor, so both are mandatory. */
    private const REQUIRED_CONTACT = [
        'mobile' => 'WhatsApp mobile number',
        'email'  => 'email address',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Profile completeness is checked before the role: an incomplete profile
        // blocks the vendor role too, so telling someone to request a role they
        // cannot be granted yet just sends them round a loop.
        if ($missing = self::missingContactFields($user)) {
            return response()->json(self::incompleteProfileResponse($missing), 403);
        }

        if (!$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You need the Vendor role to perform this action. Please request the Vendor role from your profile.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Shared with UserRoleRequestController so the wording and the
     * machine-readable field list stay identical wherever the rule is enforced.
     *
     * @param  array<int, string>  $missing
     * @return array<string, mixed>
     */
    public static function incompleteProfileResponse(array $missing): array
    {
        $labels = array_map(fn($field) => self::REQUIRED_CONTACT[$field], $missing);

        return [
            'success' => false,
            'message' => 'Fill your ' . implode(' and ', $labels) . ' first to use vendor features.',
            'data'    => ['missing_profile_fields' => $missing],
        ];
    }

    /**
     * A field is missing when nothing is stored, or when what is stored decrypts
     * to an empty string — assigning '' through the encrypted cast produces real
     * ciphertext, so the raw column alone cannot tell "empty" from "filled".
     * A row that cannot be decrypted (data migrated under a different APP_KEY,
     * User::castAttribute returns null) still counts as present: that is a key
     * problem, not a missing-profile problem, and must not lock a vendor out.
     *
     * @return array<int, string>
     */
    public static function missingContactFields($user): array
    {
        $missing = [];

        foreach (array_keys(self::REQUIRED_CONTACT) as $field) {
            $raw = trim((string) $user->getRawOriginal($field));
            $decrypted = $user->{$field};

            if ($raw === '' || ($decrypted !== null && trim((string) $decrypted) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
