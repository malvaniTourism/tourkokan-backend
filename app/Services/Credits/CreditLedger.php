<?php

namespace App\Services\Credits;

use App\Models\AiConfig;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Trip credits, on the existing `wallets` ledger.
 *
 * There is no credits table. `wallets` is already append-only with a user, an
 * amount and a description, and — checked before reuse — it is *written* only
 * (referral bonuses in AuthController) and never read for a balance anywhere,
 * so nothing existing changes meaning. A grant is a positive row, a spend is a
 * negative one, and the balance is their sum.
 *
 * Sources stay distinguishable through `description`:
 *   grant:signup · grant:referral · grant:admin · spend:trip:<id> · refund:trip:<id>
 *
 * Nothing here can be *bought* yet, deliberately: selling to tourists inside
 * the Android app would require Play Billing. Credits are granted only.
 */
class CreditLedger
{
    private const BONUS_CODE = 'trip_credit';

    private ?int $bonusId = null;

    public function balance(User $user): int
    {
        return (int) Wallet::where('user_id', $user->id)
            ->where('bonus_id', $this->bonusTypeId())
            ->sum('amount');
    }

    /** Has this user already been given their signup grant? */
    public function hasGrant(User $user, string $reason): bool
    {
        return Wallet::where('user_id', $user->id)
            ->where('bonus_id', $this->bonusTypeId())
            ->where('description', "grant:{$reason}")
            ->exists();
    }

    /**
     * The free plans a verified account starts with. Idempotent — calling it
     * twice does not double-grant, which matters because the gate runs on
     * every generation rather than once at signup.
     */
    public function grantSignupCredits(User $user): int
    {
        if ($this->hasGrant($user, 'signup')) {
            return $this->balance($user);
        }

        return $this->grant(
            $user,
            (int) AiConfig::get('free_plans_on_signup', 3),
            'signup'
        );
    }

    public function grant(User $user, int $credits, string $reason): int
    {
        if ($credits > 0) {
            Wallet::create([
                'user_id'     => $user->id,
                'bonus_id'    => $this->bonusTypeId(),
                'amount'      => $credits,
                'description' => "grant:{$reason}",
            ]);
        }

        return $this->balance($user);
    }

    /**
     * Take one credit for a generation.
     *
     * Locks the user's rows for the read so two requests cannot both see the
     * last credit.
     *
     * The spend is keyed by TRIP, not by request, so it is naturally
     * idempotent: a retry, a double tap, or the traveller re-planning the same
     * trip later all find the existing row and charge nothing. One credit buys
     * one trip and as many re-plans of it as they like, which is both simpler
     * than a per-request key and the behaviour people expect.
     *
     * @return bool false when the balance is short — the caller shows the
     *              quota screen rather than generating.
     */
    public function spend(User $user, int $tripId): bool
    {
        return DB::transaction(function () use ($user, $tripId) {
            $description = "spend:trip:{$tripId}";

            $already = Wallet::where('user_id', $user->id)
                ->where('bonus_id', $this->bonusTypeId())
                ->where('description', $description)
                ->lockForUpdate()
                ->exists();

            if ($already) {
                // Already paid for; a retry must not charge again.
                return true;
            }

            $balance = (int) Wallet::where('user_id', $user->id)
                ->where('bonus_id', $this->bonusTypeId())
                ->lockForUpdate()
                ->sum('amount');

            if ($balance < 1) {
                return false;
            }

            Wallet::create([
                'user_id'     => $user->id,
                'bonus_id'    => $this->bonusTypeId(),
                'amount'      => -1,
                'description' => $description,
            ]);

            return true;
        });
    }

    /**
     * Give the credit back. A generation that failed must never cost anyone a
     * plan — the user sees a retry, not a smaller balance.
     */
    public function refund(User $user, int $tripId): int
    {
        $spent = Wallet::where('user_id', $user->id)
            ->where('bonus_id', $this->bonusTypeId())
            ->where('description', "spend:trip:{$tripId}")
            ->exists();

        $alreadyRefunded = Wallet::where('user_id', $user->id)
            ->where('bonus_id', $this->bonusTypeId())
            ->where('description', "refund:trip:{$tripId}")
            ->exists();

        if ($spent && !$alreadyRefunded) {
            Wallet::create([
                'user_id'     => $user->id,
                'bonus_id'    => $this->bonusTypeId(),
                'amount'      => 1,
                'description' => "refund:trip:{$tripId}",
            ]);
        }

        return $this->balance($user);
    }

    /**
     * Resolved once per instance, not statically: a static would survive a
     * test's database refresh and hand back an id from the previous run.
     */
    private function bonusTypeId(): int
    {
        return $this->bonusId ??= (int) DB::table('bonus_types')
            ->where('code', self::BONUS_CODE)
            ->value('id');
    }
}
