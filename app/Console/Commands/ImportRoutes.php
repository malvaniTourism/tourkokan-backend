<?php

namespace App\Console\Commands;

use App\Imports\RouteAndRouteStopsImport;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ImportRoutes extends Command
{
    protected $signature = 'routes:import
                            {taluka? : Taluka name or CSV path. Omit to list what is available.}
                            {--all : Import every CSV in excels/Routes/Final}
                            {--queue : Dispatch to the queue instead of running inline}
                            {--no-times : Skip estimating per-stop arrival/departure times}';

    protected $description = 'Import a taluka route CSV (excels/Routes/Final) into routes + route_stops';

    private const DIR = 'excels/Routes/Final';

    /**
     * Effective km/h — derived from the Devgad dataset (median of 204 routes),
     * not a cruising speed: it already absorbs dwell time at every stop.
     * Only used for routes whose own timings are missing or implausible.
     */
    private const FALLBACK_SPEED = 29.3;

    /** Outside this band a route's own timings are treated as a data error. */
    private const MIN_SPEED = 5.0;
    private const MAX_SPEED = 60.0;

    /** Dwell added at intermediate stops so dept_time != arr_time. */
    private const DWELL_MINUTES = 1;

    public function handle(): int
    {
        $files = $this->resolveFiles();

        if ($files === []) {
            $this->components->error('Nothing to import.');
            $this->line('Available: ' . (implode(', ', $this->available()) ?: 'none — put CSVs in ' . self::DIR));

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $this->importOne($file);
        }

        return self::SUCCESS;
    }

    private function importOne(string $path): void
    {
        $taluka    = str_replace('_prod_routes', '', pathinfo($path, PATHINFO_FILENAME));
        $errorFile = "routes/{$taluka}_errors.csv";

        $before = [
            'routes' => Route::count(),
            'stops'  => RouteStops::count(),
            'sites'  => Site::count(),
        ];

        $this->components->info("Importing {$taluka} — " . basename($path));

        Excel::import(new RouteAndRouteStopsImport($errorFile, ! $this->option('queue')), $path);

        if ($this->option('queue')) {
            $this->components->warn('Queued — nothing is written until a queue worker runs.');

            return;
        }

        $this->components->twoColumnDetail('routes created', (string) (Route::count() - $before['routes']));
        $this->components->twoColumnDetail('route stops created', (string) (RouteStops::count() - $before['stops']));
        $this->components->twoColumnDetail('sites auto-created', (string) (Site::count() - $before['sites']));
        $this->components->twoColumnDetail('errors logged to', "storage/app/{$errorFile}");

        if (! $this->option('no-times')) {
            $this->estimateStopTimes($path);
        }
    }

    /**
     * Fill route_stops.arr_time / dept_time by interpolating each route's own
     * departure and arrival across its stops' cumulative distance. Anchored at
     * both ends, so an estimate can never contradict the published timings.
     */
    private function estimateStopTimes(string $path): void
    {
        $routeNos = $this->routeNumbersIn($path);
        $timed = $fallback = $skipped = 0;
        $flagged = [];

        foreach (Route::whereIn('route_no', $routeNos)->get() as $route) {
            $stops = RouteStops::where('route_id', $route->id)->orderBy('serial_no')->get();

            $span = (float) $stops->max('distance');
            if ($stops->isEmpty() || $span <= 0) {
                $skipped++;
                continue;
            }

            $start   = $this->minutes($route->start_time);
            $minutes = $this->minutes($route->end_time) - $start;
            if ($minutes <= 0) {
                $minutes += 1440;                       // arrives after midnight
            }

            // Trust the route's own pace unless it implies something impossible.
            $speed    = $minutes > 0 ? $span / ($minutes / 60) : 0;
            $estimate = false;

            if ($this->minutes($route->start_time) === 0 && $this->minutes($route->end_time) === 0) {
                $flagged[] = [$route->route_no, $route->name, round($span, 1), $minutes, '', 'no departure/arrival time in source'];
                $minutes   = (int) round($span / self::FALLBACK_SPEED * 60);
                $estimate  = true;
            } elseif ($speed < self::MIN_SPEED || $speed > self::MAX_SPEED) {
                $flagged[] = [
                    $route->route_no, $route->name, round($span, 1), $minutes, round($speed),
                    $speed > self::MAX_SPEED
                        ? 'stop distances exceed the trip distance — stop list may include the return leg'
                        : 'duration too long for the distance — check departure/arrival',
                ];
                $minutes  = (int) round($span / self::FALLBACK_SPEED * 60);
                $estimate = true;
            }

            $last = $stops->count();
            foreach ($stops->values() as $i => $stop) {
                $arrival = $start + (int) round($minutes * (((float) $stop->distance) / $span));

                // Terminals carry no dwell: the origin must depart exactly at the
                // route's start_time, and the last stop arrives at its end_time.
                $isTerminal = $i === 0 || ($i + 1) === $last;

                $stop->arr_time  = $this->clock($arrival);
                $stop->dept_time = $this->clock($isTerminal ? $arrival : $arrival + self::DWELL_MINUTES);
                $stop->meta_data = ['time_source' => $estimate ? 'estimated_fallback_speed' : 'interpolated'];
                $stop->save();
            }

            $estimate ? $fallback++ : $timed++;
        }

        $this->components->twoColumnDetail('stop times interpolated', "{$timed} routes");
        if ($fallback) {
            $this->components->twoColumnDetail('fell back to '.self::FALLBACK_SPEED.' km/h', "{$fallback} routes");
        }
        if ($skipped) {
            $this->components->twoColumnDetail('skipped (no distance)', "{$skipped} routes");
        }

        if ($flagged) {
            $taluka = str_replace('_prod_routes', '', pathinfo($path, PATHINFO_FILENAME));
            $this->components->twoColumnDetail('timing issues logged to', $this->writeTimingReport($taluka, $flagged));
        }
    }

    /** @param  array<int, array<int, mixed>>  $flagged */
    private function writeTimingReport(string $taluka, array $flagged): string
    {
        $dir = base_path('excels/Routes/Errored');
        is_dir($dir) || mkdir($dir, 0755, true);

        $relative = "excels/Routes/Errored/{$taluka}_timing_issues.csv";
        $fh = fopen(base_path($relative), 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['route_no', 'route_name', 'stop_span_km', 'source_minutes', 'implied_kmh', 'problem']);

        foreach ($flagged as $row) {
            fputcsv($fh, $row);
        }

        fclose($fh);

        return $relative;
    }

    /** @return array<int, string> */
    private function routeNumbersIn(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        // A UTF-8 BOM sits before the opening quote of the first field, so the
        // CSV reader hands back a literal `"Route No"` — strip BOM then quotes.
        $clean = fn ($h) => trim(trim((string) $h, "\xEF\xBB\xBF \t"), '"');
        $col   = array_search('Route No', array_map($clean, $header ?: []), true);

        $seen = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($col !== false && isset($row[$col]) && trim($row[$col]) !== '') {
                $seen[trim($row[$col])] = true;
            }
        }
        fclose($handle);

        return array_keys($seen);
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
            return glob(base_path(self::DIR . '/*.csv')) ?: [];
        }

        $arg = $this->argument('taluka');

        if ($arg === null) {
            return [];
        }

        // Accept either a bare taluka name or an explicit path.
        foreach ([$arg, base_path($arg), base_path(self::DIR . "/{$arg}.csv"), base_path(self::DIR . "/{$arg}_prod_routes.csv")] as $candidate) {
            if (is_file($candidate)) {
                return [$candidate];
            }
        }

        $this->components->error("No CSV found for '{$arg}'.");

        return [];
    }

    /** @return array<int, string> */
    private function available(): array
    {
        return array_map(
            fn ($f) => str_replace('_prod_routes', '', pathinfo($f, PATHINFO_FILENAME)),
            glob(base_path(self::DIR . '/*.csv')) ?: []
        );
    }
}
