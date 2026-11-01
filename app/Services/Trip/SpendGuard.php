<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\AiLog;

/**
 * The circuit breaker: a global daily ceiling on what generation may cost.
 *
 * The per-user throttle and the free quota bound what one account can do. This
 * bounds what every account together can do, which is the only defence that
 * holds when the thing going wrong is a retry loop, a scripted sign-up run, or
 * a bug in our own code rather than one greedy traveller.
 *
 * Crossing it does not switch the feature off. Cached answers keep being
 * served — they cost nothing — so travellers asking a question somebody has
 * already asked are unaffected, and only a genuinely new question is declined.
 * The provider's own console limit sits behind this as the backstop.
 */
class SpendGuard
{
    /** Rupees spent on generation today, from the logs themselves. */
    public function spentToday(): float
    {
        return (float) AiLog::whereDate('created_at', today())->sum('cost_inr');
    }

    /** Rupees spent this calendar month. */
    public function spentThisMonth(): float
    {
        return (float) AiLog::where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_inr');
    }

    public function ceiling(): float
    {
        return (float) AiConfig::get('daily_spend_ceiling', 50.00);
    }

    /**
     * Is a new, uncached generation still affordable today?
     *
     * A ceiling of zero means no spending at all, which is a legitimate
     * setting — it is how an operator freezes the bill during an incident
     * while leaving cached plans working.
     */
    public function canSpend(): bool
    {
        return $this->spentToday() < $this->ceiling();
    }

    /** What the overview screen shows. */
    public function summary(): array
    {
        $today   = round($this->spentToday(), 4);
        $ceiling = $this->ceiling();

        return [
            'today'      => $today,
            'month'      => round($this->spentThisMonth(), 4),
            'ceiling'    => $ceiling,
            'remaining'  => round(max(0, $ceiling - $today), 4),
            'percent'    => $ceiling > 0 ? (int) round($today / $ceiling * 100) : 100,
            'can_spend'  => $this->canSpend(),
        ];
    }
}
