<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Http\Request;

/**
 * Cheap, dependency-free bot/spam detection for public submission endpoints.
 *
 * Usage: call reason($request); a non-null return is a short machine-readable
 * reason the submission looks like spam. Callers should SILENTLY drop spam —
 * respond exactly as they would for a real submission — so bots get no signal
 * to adapt. Tuning lives in config/spam.php (all env-overridable).
 */
class SpamGuard
{
    /**
     * @return string|null  reason code if spam, null if the request looks legit
     */
    public function reason(Request $request): ?string
    {
        $cfg = config('spam');

        // 1. Honeypot — a hidden field real users never fill.
        $hp = $cfg['honeypot_field'] ?? null;
        if ($hp && filled($request->input($hp))) {
            return 'honeypot';
        }

        // 2. Blocked phone numbers (repeat spammers), compared digits-only.
        $phoneDigits = $this->digits($request->input('phone'));
        if ($phoneDigits !== '') {
            $blocked = array_map([$this, 'digits'], $cfg['blocked_phones'] ?? []);
            if (in_array($phoneDigits, $blocked, true)) {
                return 'blocked_phone';
            }
        }

        // 3. Blocked email domains.
        $email = strtolower(trim((string) $request->input('email')));
        if ($email !== '' && str_contains($email, '@')) {
            $domain = substr(strrchr($email, '@'), 1);
            $blockedDomains = array_map('strtolower', $cfg['blocked_email_domains'] ?? []);
            if (in_array($domain, $blockedDomains, true)) {
                return 'blocked_email_domain';
            }
        }

        // 4. Same phone reused across many different names — a classic bot tell.
        $max = (int) ($cfg['max_names_per_phone'] ?? 0);
        if ($max > 0 && $phoneDigits !== '') {
            $rawPhone = $request->input('phone');
            $distinctNames = Contact::where('phone', $rawPhone)
                ->distinct()
                ->count('name');
            if ($distinctNames >= $max) {
                return 'phone_name_churn';
            }
        }

        return null;
    }

    /** Strip everything but digits from a phone value. */
    private function digits($value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }
}
