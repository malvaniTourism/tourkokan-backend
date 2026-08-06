<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw engagement into product_daily_stats.
 *
 * Runs nightly for the previous day, but takes an explicit --date or --days so a missed
 * night can be replayed. Idempotent: the upsert on (product_id, date) means re-running any
 * day overwrites rather than doubles, which matters because the alternative — a partially
 * applied rollup — silently inflates the numbers a vendor is billed on.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class RollupProductStats extends Command
{
    protected $signature = 'products:rollup-stats
                            {--date= : A single day to roll up (YYYY-MM-DD), default yesterday}
                            {--days= : Roll up this many days back from yesterday}';

    protected $description = 'Aggregate product views and leads into product_daily_stats';

    public function handle(): int
    {
        $dates = $this->datesToProcess();

        foreach ($dates as $date) {
            $rows = $this->rollup($date);
            $this->info("{$date->toDateString()}: {$rows} product(s) rolled up.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, Carbon>
     */
    private function datesToProcess(): array
    {
        if ($this->option('date')) {
            return [Carbon::parse($this->option('date'))->startOfDay()];
        }

        $days = (int) ($this->option('days') ?: 1);

        return collect(range(1, max(1, $days)))
            ->map(fn($back) => Carbon::yesterday()->subDays($back - 1)->startOfDay())
            ->all();
    }

    private function rollup(Carbon $date): int
    {
        $day = $date->toDateString();

        $views = DB::table('product_view_events')
            ->selectRaw('product_id, COUNT(*) as views, COUNT(DISTINCT session_hash) as unique_views')
            ->whereDate('created_at', $day)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $leads = DB::table('product_leads')
            ->selectRaw(
                'product_id, COUNT(*) as leads,'
                . " SUM(lead_type = 'call') as leads_call,"
                . " SUM(lead_type = 'whatsapp') as leads_whatsapp,"
                . " SUM(lead_type = 'directions') as leads_directions,"
                . " SUM(lead_type = 'enquiry') as leads_enquiry"
            )
            ->whereDate('created_at', $day)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $productIds = $views->keys()->merge($leads->keys())->unique();

        if ($productIds->isEmpty()) {
            return 0;
        }

        $now  = now();
        $rows = $productIds->map(fn($id) => [
            'product_id'       => $id,
            'date'             => $day,
            'views'            => (int) ($views[$id]->views ?? 0),
            'unique_views'     => (int) ($views[$id]->unique_views ?? 0),
            'leads'            => (int) ($leads[$id]->leads ?? 0),
            'leads_call'       => (int) ($leads[$id]->leads_call ?? 0),
            'leads_whatsapp'   => (int) ($leads[$id]->leads_whatsapp ?? 0),
            'leads_directions' => (int) ($leads[$id]->leads_directions ?? 0),
            'leads_enquiry'    => (int) ($leads[$id]->leads_enquiry ?? 0),
            'created_at'       => $now,
            'updated_at'       => $now,
        ])->all();

        // Upsert rather than insert: a replayed day must overwrite, never accumulate.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_daily_stats')->upsert(
                $chunk,
                ['product_id', 'date'],
                ['views', 'unique_views', 'leads', 'leads_call', 'leads_whatsapp',
                 'leads_directions', 'leads_enquiry', 'updated_at']
            );
        }

        return count($rows);
    }
}
