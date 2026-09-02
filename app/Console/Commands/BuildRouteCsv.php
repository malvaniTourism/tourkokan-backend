<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Joins a taluka duty sheet (one row per trip) against the master stop export
 * (one row per stop) to produce a seedable CSV that keeps the master's exact
 * column structure, with the trip columns appended.
 */
class BuildRouteCsv extends Command
{
    protected $signature = 'routes:build
                            {source : Taluka name (resolved in excels/Routes/Source) or a path to a duty-sheet CSV}
                            {--taluka= : Output name; defaults to the first word of the source filename}
                            {--master=excels/AllRoutesWithStopsCSV.csv : Master stop export}';

    protected $description = 'LOCAL ONLY: build excels/Routes/Final/<taluka>_prod_routes.csv from a taluka duty sheet';

    /*
     * Run this on a developer machine and commit the result. It needs the
     * 12 MB master export and the raw duty sheets, neither of which the
     * servers use; they run routes:import against the committed CSV so
     * every environment seeds byte-identical data.
     */

    /** Raw duty sheets live in the repo so the pipeline is reproducible. */
    private const SOURCE_DIR = 'excels/Routes/Source';

    /** A trip implying a speed outside this band is treated as mistyped. */
    private const MIN_SPEED = 5.0;
    private const MAX_SPEED = 60.0;

    public function handle(): int
    {
        $source = $this->resolveSource($this->argument('source'));
        if ($source === null) {
            $this->components->error('Source not found: '.$this->argument('source'));
            $this->line('  Available in '.self::SOURCE_DIR.': '.(implode(', ', $this->availableSources()) ?: 'none'));

            return self::FAILURE;
        }

        $taluka = strtolower($this->option('taluka')
            ?: explode('_', pathinfo($source, PATHINFO_FILENAME))[0]);

        $trips = $this->pickTrips($source);
        $this->components->info(sprintf('%s: %d routes from %d trips', $taluka, count($trips['chosen']), $trips['total']));

        if ($trips['rescued']) {
            $this->components->twoColumnDetail('better trip chosen over first', (string) $trips['rescued']);
        }

        $result = $this->join($trips['chosen'], $taluka);

        $this->components->twoColumnDetail('stop rows written', (string) $result['rows']);
        $this->components->twoColumnDetail('routes matched', sprintf('%d of %d', $result['matched'], count($trips['chosen'])));
        $this->components->twoColumnDetail('output', $result['out']);

        $unmatched = array_diff(array_keys($trips['chosen']), $result['hit']);
        if ($unmatched || $trips['blank']) {
            $this->writeUnmatched($taluka, $unmatched, $trips['chosen'], $trips['blank']);
            $this->components->warn(sprintf(
                '%d routes not in master, %d rows had no route number — see excels/Routes/Errored/%s_unmatched.csv',
                count($unmatched), count($trips['blank']), $taluka
            ));
        }

        return self::SUCCESS;
    }

    /**
     * One trip per route. Where a route runs several times a day the master has
     * only one stop list, so pick the trip whose distance and duration imply a
     * believable speed — taking the first blindly inherits its typos.
     */
    private function pickTrips(string $source): array
    {
        $handle = fopen($source, 'r');
        $header = $this->header($handle);

        $byRoute = [];
        $blank   = [];
        $total   = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $r = @array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
            if (! $r) {
                continue;
            }
            $total++;

            $key = trim($r['Route_No'] ?? '') ?: trim($r['Old_Route_No'] ?? '');
            if ($key === '') {
                $blank[] = $r;
                continue;
            }

            $byRoute[$key][] = $r;
        }
        fclose($handle);

        $chosen = $rescued = [];
        foreach ($byRoute as $key => $trips) {
            $best = $trips[0];

            if (count($trips) > 1 && ! $this->plausible($best)) {
                foreach ($trips as $trip) {
                    if ($this->plausible($trip)) {
                        $best = $trip;
                        $rescued[] = $key;
                        break;
                    }
                }
            }

            $chosen[$key] = $best;
        }

        return ['chosen' => $chosen, 'blank' => $blank, 'total' => $total, 'rescued' => count($rescued)];
    }

    /**
     * Depot sheets are hand-built and don't share a column vocabulary: Devgad
     * has Remark / School_Trip / Rest_Remark, Vengurla folds all three into
     * Halt_Note. Read whichever the sheet actually has.
     *
     * @param  array<string, string>  $trip
     */
    private function tripValue(array $trip, string $canonical): string
    {
        // Key presence, not emptiness: a sheet that HAS the column is
        // authoritative, and a blank there means blank — never inferred.
        if (array_key_exists($canonical, $trip)) {
            return $trip[$canonical];
        }

        $aliases = [
            'Remark'      => 'Halt_Note',
            'Rest_Remark' => 'Halt_Note',
        ];

        if (isset($aliases[$canonical]) && array_key_exists($aliases[$canonical], $trip)) {
            return $trip[$aliases[$canonical]];
        }

        // Only where the sheet has no School_Trip column at all: these sheets
        // mark school runs with शालेय in the halt note.
        if ($canonical === 'School_Trip') {
            return str_contains($trip['Halt_Note'] ?? '', 'शालेय') ? 'SCHOOL' : '';
        }

        return '';
    }

    /** Accept a bare taluka name, a repo-relative path, or an absolute path. */
    private function resolveSource(string $arg): ?string
    {
        $candidates = [
            $arg,
            base_path($arg),
            base_path(self::SOURCE_DIR."/{$arg}.csv"),
            base_path(self::SOURCE_DIR.'/'.ucfirst($arg).'_Bus_Routes_Final.csv'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Fall back to a case-insensitive prefix match on the source directory.
        foreach ($this->sourceFiles() as $file) {
            if (str_starts_with(strtolower(basename($file)), strtolower($arg))) {
                return $file;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function sourceFiles(): array
    {
        return glob(base_path(self::SOURCE_DIR.'/*.csv')) ?: [];
    }

    /** @return array<int, string> */
    private function availableSources(): array
    {
        return array_map(fn ($f) => explode('_', basename($f, '.csv'))[0], $this->sourceFiles());
    }

    private function plausible(array $trip): bool
    {
        $km = (float) ($trip['Distance_KM'] ?? 0);
        $a  = $this->minutes($trip['Departure_Time'] ?? '');
        $b  = $this->minutes($trip['Arrival_Time'] ?? '');

        if ($km <= 0 || $a === null || $b === null) {
            return false;
        }

        $mins = $b - $a;
        if ($mins <= 0) {
            $mins += 1440;
        }

        $speed = $km / ($mins / 60);

        return $speed >= self::MIN_SPEED && $speed <= self::MAX_SPEED;
    }

    /** Mirrors the importer's tolerance for hand-typed separators. */
    private function minutes(string $raw): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', preg_replace('/[.,;:]+/', ':', trim($raw)), $m)) {
            return null;
        }

        return $m[1] > 23 || $m[2] > 59 ? null : ((int) $m[1]) * 60 + (int) $m[2];
    }

    /** Stream the master export, emitting stop rows for the chosen trips. */
    private function join(array $chosen, string $taluka): array
    {
        $master = fopen(base_path($this->option('master')), 'r');
        $cols   = $this->header($master);

        $extra = ['Source_Marathi', 'Destination_Marathi', 'Distance_KM', 'Departure_Time',
            'Arrival_Time', 'Trip_Type', 'Frequency', 'Rest_Remark', 'Remark', 'School_Trip'];

        $dir = base_path('excels/Routes/Final');
        is_dir($dir) || mkdir($dir, 0755, true);
        $outPath = "{$dir}/{$taluka}_prod_routes.csv";

        $out = fopen($outPath, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge($cols, $extra));

        $rows = 0;
        $hit  = [];

        while (($row = fgetcsv($master)) !== false) {
            $rn = trim($row[0] ?? '');
            if (! isset($chosen[$rn])) {
                continue;
            }

            $t = $chosen[$rn];
            $hit[$rn] = true;

            fputcsv($out, array_merge(
                array_pad(array_slice($row, 0, count($cols)), count($cols), ''),
                array_map(fn ($k) => $this->tripValue($t, $k), $extra)
            ));
            $rows++;
        }

        fclose($master);
        fclose($out);

        return ['rows' => $rows, 'matched' => count($hit), 'hit' => array_keys($hit), 'out' => "excels/Routes/Final/{$taluka}_prod_routes.csv"];
    }

    private function writeUnmatched(string $taluka, array $unmatched, array $chosen, array $blank): void
    {
        $dir = base_path('excels/Routes/Errored');
        is_dir($dir) || mkdir($dir, 0755, true);

        $fh = fopen("{$dir}/{$taluka}_unmatched.csv", 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['reason', 'Route_No', 'Old_Route_No', 'Duty_No', 'Source_English', 'Destination_English', 'Departure_Time']);

        foreach ($unmatched as $key) {
            $t = $chosen[$key];
            fputcsv($fh, ['route_no not in master', $t['Route_No'] ?? '', $t['Old_Route_No'] ?? '',
                $t['Duty_No'] ?? '', $t['Source_English'] ?? '', $t['Destination_English'] ?? '', $t['Departure_Time'] ?? '']);
        }

        foreach ($blank as $t) {
            fputcsv($fh, ['no route number', '', $t['Old_Route_No'] ?? '', $t['Duty_No'] ?? '',
                $t['Source_English'] ?? '', $t['Destination_English'] ?? '', $t['Departure_Time'] ?? '']);
        }

        fclose($fh);
    }

    /** @return array<int, string> */
    private function header($handle): array
    {
        $header = fgetcsv($handle) ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');

        return array_map(fn ($h) => trim($h), $header);
    }
}
