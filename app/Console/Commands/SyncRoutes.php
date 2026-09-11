<?php

namespace App\Console\Commands;

use App\Models\BusType;
use App\Models\Category;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent route sync from a corrected excels/Routes/Final CSV.
 *
 * `routes:import` is create-only: it skips a route_no it has already seen and appends any
 * renamed stop at the end, so re-importing an edited CSV corrupts live routes. This command
 * is the safe counterpart — after it exists, **correcting a CSV and re-running is the whole
 * update workflow, and running it twice changes nothing.**
 *
 * Per route, inside one transaction:
 *   - the route row is upserted by route_no (name, times, distance, source/dest, meta refreshed);
 *   - its stops are rebuilt to exactly match the CSV order, consecutive duplicates collapsed,
 *     serial_no renumbered from 1;
 *   - stop arrival/departure times are re-interpolated across the route's own timings.
 *
 * The route ROW is never deleted, so its id — and everything keyed to it — survives. Only its
 * stop rows are replaced. Dry-run by default; nothing is written without --apply.
 */
class SyncRoutes extends Command
{
    protected $signature = 'routes:sync
                            {taluka? : Taluka name or CSV path. Omit with --all.}
                            {--all : Sync every CSV in excels/Routes/Final}
                            {--apply : Persist changes. Without it, this is a dry run.}
                            {--no-times : Leave stop times at 00:00:00 instead of interpolating}';

    protected $description = 'Idempotently sync routes + stops from a corrected Final CSV (safe to re-run)';

    private const DIR = 'excels/Routes/Final';
    private const FALLBACK_SPEED = 29.3;
    private const DWELL_MINUTES = 1;

    public function handle(): int
    {
        $files = $this->resolveFiles();

        if ($files === []) {
            $this->components->error('Nothing to sync. Put CSVs in ' . self::DIR . ' or pass a taluka.');
            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->components->warn('DRY RUN — no changes will be written. Add --apply to commit.');
        }

        foreach ($files as $file) {
            $this->syncOne($file);
        }

        return self::SUCCESS;
    }

    private function syncOne(string $path): void
    {
        $taluka = str_replace('_prod_routes', '', pathinfo($path, PATHINFO_FILENAME));
        $this->components->info("Syncing {$taluka} — " . basename($path));

        $routes = $this->readGroupedByRoute($path);

        $stats = ['routes_created' => 0, 'routes_updated' => 0, 'stops_added' => 0,
                  'stops_removed' => 0, 'stops_reordered' => 0, 'sites_created' => 0];

        DB::beginTransaction();
        try {
            foreach ($routes as $routeNo => $rows) {
                $this->syncRoute((string) $routeNo, $rows, $stats);
            }

            if ($this->option('apply')) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->components->error("Aborted {$taluka}: {$e->getMessage()} — nothing written for this file.");
            return;
        }

        foreach ($stats as $label => $n) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $label), (string) $n);
        }
        $this->components->twoColumnDetail(
            'result',
            $this->option('apply') ? '<info>committed</info>' : '<comment>rolled back (dry run)</comment>'
        );
    }

    /**
     * Upsert one route and replace its stop list to match the CSV exactly.
     *
     * @param  array<int, array<string, string>>  $rows  the CSV rows for this route, in file order
     * @param  array<string, int>  $stats
     */
    private function syncRoute(string $routeNo, array $rows, array &$stats): void
    {
        $first = $rows[0];

        $source = $this->resolveSite($first['from_stop_name'], $stats);
        $dest   = $this->resolveSite($first['till_stop_name'], $stats);

        $start = $this->toTime($first['departure_time'] ?? null) ?? '00:00:00';
        $end   = $this->toTime($first['arrival_time'] ?? null) ?? '00:00:00';

        $attrs = [
            'source_place_id'      => $source->id,
            'destination_place_id' => $dest->id,
            'name'                 => $first['route_name'],
            'start_time'           => $start,
            'end_time'             => $end,
            'total_time'           => $this->duration($start, $end),
            'working_days'         => $first['frequency'] ?? null,
            'distance'             => $this->num($first['distance_km'] ?? $first['dist_km'] ?? null),
            'meta_data'            => $this->tripMeta($first),
        ];

        $route = Route::where('route_no', $routeNo)->first();
        if ($route) {
            $route->fill($attrs)->save();
            $stats['routes_updated']++;
        } else {
            $route = Route::create($attrs + [
                'route_no'    => $routeNo,
                'bus_type_id' => optional(BusType::where('type', 'Ordinary Express')->first())->id,
            ]);
            $stats['routes_created']++;
        }

        // Desired ordered stop list from the CSV, consecutive duplicates collapsed.
        $desired = [];  // [ [site_id, distance], ... ]
        foreach ($rows as $r) {
            $site = $this->resolveSite($r['bstop_name'], $stats);
            $dist = $this->num($r['dist_km'] ?? null);
            if ($desired && end($desired)[0] === $site->id) {
                continue; // same stop twice in a row — one physical stop
            }
            $desired[] = [$site->id, $dist];
        }

        $existing = RouteStops::where('route_id', $route->id)->orderBy('serial_no')->get();
        $before   = $existing->pluck('site_id')->all();
        $after    = array_column($desired, 0);

        $stats['stops_added']   += max(0, count(array_diff($after, $before)));
        $stats['stops_removed'] += max(0, count(array_diff($before, $after)));
        if ($before !== $after && !array_diff($before, $after) && !array_diff($after, $before)) {
            $stats['stops_reordered']++; // same set, different order
        }

        // Rebuild: the route row (and its id) stays; only stop rows are replaced, so order
        // and membership become exactly the CSV with no possibility of stale rows.
        RouteStops::where('route_id', $route->id)->delete();

        $serial = 1;
        foreach ($desired as [$siteId, $dist]) {
            RouteStops::create([
                'route_no'  => $routeNo,
                'serial_no' => $serial++,
                'route_id'  => $route->id,
                'site_id'   => $siteId,
                'distance'  => $dist,
                'arr_time'  => '00:00:00',
                'dept_time' => '00:00:00',
                'total_time'   => '00:00:00',
                'delayed_time' => '00:00:00',
                'meta_data' => null,
            ]);
        }

        if (! $this->option('no-times')) {
            $this->interpolateTimes($route);
        }
    }

    /** Fill arr/dept across the route's own timings, anchored at both terminals. */
    private function interpolateTimes(Route $route): void
    {
        $stops = RouteStops::where('route_id', $route->id)->orderBy('serial_no')->get();
        $span  = (float) $stops->max('distance');
        if ($stops->isEmpty() || $span <= 0) {
            return;
        }

        $start   = $this->minutes($route->start_time);
        $minutes = $this->minutes($route->end_time) - $start;
        if ($minutes <= 0) {
            $minutes += 1440;
        }
        $speed = $minutes > 0 ? $span / ($minutes / 60) : 0;

        // No usable timing — fall back to a derived pace rather than leaving zeros.
        if (($start === 0 && $this->minutes($route->end_time) === 0) || $speed < 5 || $speed > 60) {
            $minutes = (int) round($span / self::FALLBACK_SPEED * 60);
        }

        $last = $stops->count();
        foreach ($stops->values() as $i => $stop) {
            $arrival    = $start + (int) round($minutes * (((float) $stop->distance) / $span));
            $isTerminal = $i === 0 || ($i + 1) === $last;
            $stop->arr_time  = $this->clock($arrival);
            $stop->dept_time = $this->clock($isTerminal ? $arrival : $arrival + self::DWELL_MINUTES);
            $stop->save();
        }
    }

    // ── CSV reading ───────────────────────────────────────────────────────────────

    /**
     * Read a Final CSV grouped by Route No, preserving row order (= stop order).
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function readGroupedByRoute(string $path): array
    {
        $fh = fopen($path, 'r');
        $header = array_map($this->cleanHeader(...), fgetcsv($fh) ?: []);

        $grouped = [];
        while (($row = fgetcsv($fh)) !== false) {
            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = isset($row[$i]) ? trim((string) $row[$i]) : null;
            }
            $rn = $assoc['route_no'] ?? '';
            if ($rn === '') {
                continue;
            }
            $grouped[$rn][] = $assoc;
        }
        fclose($fh);

        return $grouped;
    }

    /** "Route No" -> route_no, stripping the UTF-8 BOM and surrounding quotes. */
    private function cleanHeader(?string $h): string
    {
        $h = trim(trim((string) $h, "\xEF\xBB\xBF \t"), '"');
        return str_replace(' ', '_', strtolower($h));
    }

    // ── Site resolution ─────────────────────────────────────────────────────────────

    private function resolveSite(string $name, array &$stats): Site
    {
        $name = trim($name);
        $site = Site::where('name', $name)->first();
        if ($site) {
            return $site;
        }

        $stats['sites_created']++;
        $site = Site::create([
            'name' => $name, 'bus_stop_type' => 'Stop', 'status' => true, 'is_hot_place' => false,
        ]);
        $category = Category::firstOrCreate(['code' => 'other'], ['name' => 'Other', 'parent_id' => null]);
        $site->categories()->attach($category);

        return $site;
    }

    // ── Small helpers (mirrors ProcessRouteImport) ──────────────────────────────────

    private function num($v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    private function toTime($raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $n = preg_replace('/[.,;:]+/', ':', trim($raw));
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $n, $m) || $m[1] > 23 || $m[2] > 59) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }

    private function duration(?string $start, ?string $end): string
    {
        if (! $start || ! $end || ($start === '00:00:00' && $end === '00:00:00')) {
            return '00:00:00';
        }
        $from = strtotime($start);
        $to   = strtotime($end);
        if ($to < $from) {
            $to += 86400;
        }
        return gmdate('H:i:s', $to - $from);
    }

    private function tripMeta(array $v): ?string
    {
        $meta = array_filter([
            'trip_type'   => $v['trip_type'] ?? null,
            'school_trip' => $v['school_trip'] ?? null,
            'remark'      => $v['remark'] ?? null,
            'rest_remark' => $v['rest_remark'] ?? null,
            'source_mr'   => $v['source_marathi'] ?? null,
            'dest_mr'     => $v['destination_marathi'] ?? null,
        ], fn($x) => $x !== null && $x !== '');

        return $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
    }

    private function minutes(?string $time): int
    {
        [$h, $m] = array_pad(explode(':', (string) $time), 2, 0);
        return ((int) $h) * 60 + (int) $m;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    /** @return array<int, string> */
    private function resolveFiles(): array
    {
        if ($this->option('all')) {
            return glob(base_path(self::DIR . '/*_prod_routes.csv')) ?: [];
        }

        $arg = $this->argument('taluka');
        if (! $arg) {
            $this->components->error('Pass a taluka name/path or use --all.');
            return [];
        }
        if (is_file($arg)) {
            return [$arg];
        }

        $guess = base_path(self::DIR . '/' . strtolower($arg) . '_prod_routes.csv');
        return is_file($guess) ? [$guess] : [];
    }
}
