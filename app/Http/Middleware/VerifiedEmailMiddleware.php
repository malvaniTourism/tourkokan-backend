<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Generation requires an identity, not merely a token.
 *
 * This is easy to get wrong, because the app issues guests a working JWT:
 * `/register` with `is_guest` creates an email-less account that
 * `users:soft-delete-guests` removes after 48 hours. `auth:api` alone would
 * happily let one of those generate.
 *
 * Two reasons a guest must convert first, and the second is the one that
 * matters: free credits are granted against an identity, so without an email
 * there is nothing to grant them to and nothing to stop the same person
 * starting over; and a guest costs nothing to create, so an ungated endpoint
 * is an uncapped spend endpoint.
 *
 * Browsing, the cart and the sample trip stay open to guests. Only generation
 * is gated — and `googleAuth` sets `email_verified_at` itself, so that path
 * satisfies this in one tap with no code to type.
 */
class VerifiedEmailMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->email_verified_at === null) {
            // HTTP 200 with the envelope, like every other logical refusal in
            // this API. The app maps `verification_required` to the
            // save-your-trip screen, which keeps the cart the guest built.
            return response()->json([
                'success' => false,
                'message' => 'Confirm your email to plan a trip. Your places are saved.',
                'data'    => ['reason' => 'verification_required'],
            ], 200);
        }

        return $next($request);
    }
}
