<?php

namespace App\Console\Commands;

use App\Models\ProductDailyStat;
use App\Models\ProductViewEvent;
use Illuminate\Console\Command;

/**
 * Discards raw view events once they have been rolled up.
 *
 * The aggregate in product_daily_stats is permanent, so nothing of reporting value is lost —
 * but the raw log grows without bound and carries per-session data there is no reason to
 * keep. Leads are never pruned: they are the billable record.
 *
 * Refuses to delete a day that has no rollup row, so a failed nightly job cannot quietly
 * cost you the underlying data as well.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class PruneProductViewEvents extends Command
{
    protected $signature = 'products:prune-view-events
                            {--days=90 : Delete raw view events older than this}
                            {--force : Prune even where no rollup exists for that day}';

    protected $description = 'Delete raw product view events that have already been rolled up';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $cutoff = now()->subDays($days)->startOfDay();

        $stale = ProductViewEvent::where('created_at', '<', $cutoff);

        if (!$stale->exists()) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            $unrolled = $this->daysWithoutRollup($cutoff);

            if ($unrolled->isNotEmpty()) {
                $this->error(
                    'Refusing to prune — no rollup exists for: ' . $unrolled->implode(', ')
                    . '. Run `products:rollup-stats --date=<day>` first, or pass --force.'
                );

                return self::FAILURE;
            }
        }

        $deleted = $stale->delete();

        $this->info("Pruned {$deleted} raw view event(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }

    /**
     * Days that still hold raw events but were never aggregated.
     */
    private function daysWithoutRollup(\Illuminate\Support\Carbon $cutoff)
    {
        $days = ProductViewEvent::where('created_at', '<', $cutoff)
            ->selectRaw('DATE(created_at) as day')
            ->distinct()
            ->pluck('day');

        $rolled = ProductDailyStat::whereIn('date', $days)->pluck('date')
            ->map(fn($d) => $d->toDateString());

        return $days->diff($rolled)->values();
    }
}
