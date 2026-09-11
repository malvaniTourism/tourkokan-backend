<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate sites.locality so route search can treat granular sub-stops of one place as one.
 *
 * A locality is the leading part of a stop name before its first descriptive qualifier
 * ("Are School" -> "Are"). Junction words (Fata/Titha/Naka) are kept IN the name, so
 * "Are Fata" stays its own locality — a bus that only passes the junction must not be
 * returned as serving the village.
 *
 * Only groups of TWO OR MORE stops sharing a base get a locality; a unique stop is left null
 * and keeps exact-match behaviour. Read only by RouteController::routes(); safe to re-run.
 */
class GroupStopLocalities extends Command
{
    protected $signature = 'routes:group-localities
                            {--apply : Persist. Without it, prints the grouping and writes nothing.}
                            {--show= : Print the members of this one locality and exit.}';

    protected $description = 'Group granular bus stops under a shared locality for route search';

    /** Kept in the locality name — a junction is a distinct place from the village it names. */
    private const JUNCTION = ['fata', 'phata', 'titha', 'tittha', 'naka'];

    /**
     * First words too generic to identify a place — they repeat in every town, so grouping on
     * them would merge unrelated stops (Devgad's tahsil office with Malvan's). Never grouped.
     */
    private const GENERIC = [
        'tahsil', 'grampanchayat', 'gram', 'panchayat', 'police', 'zilla', 'post',
        'bus', 'st', 'std', 'market', 'hospital', 'court', 'collector', 'railway',
        'shri', 'shree', 'sant', 'dev',
    ];

    /** Stripped to form the base — a descriptive sub-point of the same locality. */
    private const QUALIFIER = [
        'school', 'highschool', 'mandir', 'temple', 'wadi', 'dhangarwadi', 'boudhawadi',
        'musalmanwadi', 'shala', 'college', 'hospital', 'gpo', 'sada', 'taka', 'whal',
        'bandar', 'stand', 'depo', 'depot', 'stop', 'cross', 'chowk', 'bridge', 'dukan',
        'gaon', 'nagar', 'colony', 'ves', 'khind', 'ghat', 'market', 'bazar', 'bazaar',
        'point', 'pump', 'tank', 'well', 'office',
    ];

    public function handle(): int
    {
        $sites = Site::select('id', 'name')->get();

        // locality key -> [site ids]. A null key never groups.
        $groups = [];
        foreach ($sites as $s) {
            $key = $this->localityKey($s->name);
            if ($key !== null) {
                $groups[$key][] = $s->id;
            }
        }

        if ($this->option('show')) {
            $base = $this->localityKey($this->option('show')) ?? $this->option('show');
            $ids = $groups[$base] ?? [];
            $this->info("locality '{$base}': " . count($ids) . ' stops');
            foreach (Site::whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
                $this->line("  #{$id}  {$name}");
            }
            return self::SUCCESS;
        }

        // Only multi-member groups become a locality; singletons stay null (exact match).
        $multi = array_filter($groups, fn($ids) => count($ids) >= 2);

        $localities = count($multi);
        $stops = array_sum(array_map('count', $multi));
        $this->components->info(($this->option('apply') ? 'Applying' : 'DRY RUN —')
            . " {$localities} localities covering {$stops} stops (of {$sites->count()})");

        // Largest groups first — the ones most worth eyeballing for over-grouping.
        uasort($multi, fn($a, $b) => count($b) <=> count($a));
        $preview = array_slice($multi, 0, 12, true);
        foreach ($preview as $base => $ids) {
            $names = Site::whereIn('id', $ids)->pluck('name')->take(6)->implode(', ');
            $this->line(sprintf('  %-22s %2d  [%s%s]', $base, count($ids), $names,
                count($ids) > 6 ? ', …' : ''));
        }

        if (! $this->option('apply')) {
            $this->components->warn('Nothing written. Re-run with --apply. Inspect one with --show="Are".');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($multi) {
            Site::query()->update(['locality' => null]); // idempotent: recompute from scratch
            foreach ($multi as $base => $ids) {
                Site::whereIn('id', $ids)->update(['locality' => $base]);
            }
        });

        $this->components->info('Localities written to sites.locality.');
        return self::SUCCESS;
    }

    /**
     * The locality a stop belongs to, or null if it must not be grouped.
     *
     * "Are School" -> "Are"; "Are Fata" -> "Are Fata" (junction kept); "Tahsil Office, Devgad"
     * -> null (generic civic name). A trailing ", Town" is folded into the key so the same
     * name in two towns ("…, Devgad" vs "…, Malvan") never merges.
     */
    private function localityKey(string $name): ?string
    {
        // Split off a trailing ", Town" — it disambiguates the place and must stay in the key.
        $parts = explode(',', $name, 2);
        $main  = trim($parts[0]);
        $town  = isset($parts[1]) ? trim($parts[1]) : '';

        $base = $this->baseOf($main);
        if ($base === '') {
            return null;
        }

        $firstWord = preg_replace('/[^a-z]/', '', strtolower(preg_split('/\s+/', $base)[0]));
        if (in_array($firstWord, self::GENERIC, true)) {
            return null; // repeats across towns — identifying nothing on its own
        }

        return $town !== '' ? "{$base}, {$town}" : $base;
    }

    private function baseOf(string $name): string
    {
        $toks = preg_split('/\s+/', trim($name));
        if (count($toks) <= 1) {
            return $toks[0] ?? '';
        }
        $out = [$toks[0]];
        foreach (array_slice($toks, 1) as $t) {
            $key = preg_replace('/[^a-z]/', '', strtolower($t));
            if (in_array($key, self::JUNCTION, true)) {
                $out[] = $t;   // keep the junction word, then stop
                break;
            }
            if (in_array($key, self::QUALIFIER, true)) {
                break;         // drop the qualifier and everything after
            }
            $out[] = $t;
        }
        return implode(' ', $out);
    }
}
