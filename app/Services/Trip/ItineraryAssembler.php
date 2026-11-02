<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The security boundary, and the last thing that runs before a user sees a plan.
 *
 * The model returns an ordering and some prose. This throws away everything
 * else it said, checks every id against the set that was actually offered, and
 * rebuilds each stop from the live row. That is why a phone number in a
 * finished itinerary is the one currently in the database rather than one a
 * model reproduced from its input — and why a hallucinated place cannot reach
 * a traveller even if the model invents one.
 */
class ItineraryAssembler
{
    public function __construct(private LegResolver $legs)
    {
    }

    /**
     * @param  array<string,mixed>  $raw              what the model returned
     * @param  array<int,int>       $allowedSiteIds   what it was offered
     * @param  array<int,int>       $requiredSiteIds  the traveller's own picks
     *
     * @return array{
     *     ok: bool,
     *     itinerary: array<string,mixed>|null,
     *     dropped: array<int,int>,
     *     reason: string|null,
     *     sites_used: int
     * }
     */
    public function assemble(
        array $raw,
        array $allowedSiteIds,
        int $days,
        array $requiredSiteIds = [],
        ?string $arrivalStart = null,
        /** Site models that were never saved — the traveller's own pins. */
        $extraSites = null,
    ): array {
        $minPerDay = (int) AiConfig::get('min_stops_per_day', 2);
        $maxPerDay = (int) AiConfig::get('max_stops_per_day', 5);

        $allowed = array_flip($allowedSiteIds);
        $dropped = [];
        $usedIds = [];
        $clean   = [];

        foreach ($raw['days'] ?? [] as $day) {
            $stops = [];

            foreach ($day['stops'] ?? [] as $stop) {
                $id = (int) ($stop['site_id'] ?? 0);

                // Rule 2: a stranger is dropped and counted, never shown.
                if ($id === 0 || !isset($allowed[$id])) {
                    $dropped[] = $id;
                    continue;
                }

                // A repeat across days is a drop too — one visit per place.
                if (in_array($id, $usedIds, true)) {
                    $dropped[] = $id;
                    continue;
                }

                $usedIds[] = $id;
                $stops[]   = [
                    'site_id' => $id,
                    'arrive'  => $this->time($stop['arrive'] ?? null),
                    'depart'  => $this->time($stop['depart'] ?? null),
                    'note'    => (string) ($stop['note'] ?? ''),
                ];

                if (count($stops) >= $maxPerDay) {
                    break;
                }
            }

            if ($stops === []) {
                continue;
            }

            $clean[] = [
                'day'   => count($clean) + 1,
                'theme' => (string) ($day['theme'] ?? ''),
                'stops' => $stops,
            ];
        }

        // Rule 5: a thin day is a failure, not something to paper over. The
        // caller returns the credit rather than showing a two-stop "3-day trip".
        if ($clean === []) {
            return $this->fail($dropped, 'no_valid_stops');
        }

        // A traveller still on a train at 17:54 cannot make two stops that
        // evening, so day one is shifted to when they actually arrive and is
        // exempt from the daily minimum. Enforced here rather than trusted to
        // the model, like every other fact in this file.
        if ($arrivalStart !== null) {
            $clean[0]['stops'] = $this->afterArrival($clean[0]['stops'], $arrivalStart);
        }

        // A traveller who picks three places for two days has asked for
        // something their own picks cannot satisfy: two days need four stops.
        // The gap is meant to come from `suggested`, and the prompt says so —
        // but a model that ignores it used to cost the whole plan, and the
        // traveller got nothing after a real call had already been paid for.
        //
        // So fill the short day here instead, from the same vetted list the
        // model was offered. Nothing is invented: every added place was already
        // allowed, is nearest to what that day already holds, and is rebuilt
        // from its live row like every other stop.
        $clean = $this->fillThinDays(
            $clean, array_keys($allowed), $usedIds, $requiredSiteIds, $minPerDay, $arrivalStart
        );

        foreach ($clean as $index => $day) {
            $exempt = $arrivalStart !== null && $index === 0;

            // Still short after the top-up means the offered list was genuinely
            // too small. That is a real failure and is still reported as one.
            if (!$exempt && count($day['stops']) < $minPerDay) {
                return $this->fail($dropped, 'day_too_thin');
            }
        }

        if (count($clean) < $days) {
            return $this->fail($dropped, 'fewer_days_than_requested');
        }

        // Every place the traveller personally picked must have survived.
        $missing = array_diff($requiredSiteIds, $usedIds);
        if ($missing !== []) {
            return $this->fail($dropped, 'picked_place_missing');
        }

        return [
            'ok'         => true,
            'itinerary'  => $this->rehydrate($raw, $clean, $extraSites),
            'dropped'    => $dropped,
            'reason'     => null,
            'sites_used' => count($usedIds),
        ];
    }

    /**
     * Rebuild the plan from our own rows. The model's ordering and prose are
     * kept; every fact is re-read from the database.
     */
    private function rehydrate(array $raw, array $clean, $extraSites = null): array
    {
        $ids = collect($clean)->flatMap(fn ($d) => array_column($d['stops'], 'site_id'))->all();

        /** @var Collection<int,Site> $sites */
        $sites = Site::whereIn('id', $ids)
            ->with(['categories:id,name,code'])
            ->get()
            ->keyBy('id');

        // The traveller's own pins carry negative ids and have no row to
        // read, so they arrive already built. Merged in here rather than
        // special-cased below, so everything downstream — the legs, the
        // ordering, the shape of a stop — treats them like any other place.
        if ($extraSites) {
            foreach ($extraSites as $extra) {
                $sites->put((int) $extra->id, $extra);
            }
        }

        $days = [];

        foreach ($clean as $day) {
            $stops = [];
            $ordered = [];

            foreach ($day['stops'] as $stop) {
                $site = $sites->get($stop['site_id']);
                if (!$site) {
                    continue;
                }

                $ordered[] = $site;
                $stops[]   = [
                    'arrive' => $stop['arrive'],
                    'depart' => $stop['depart'],
                    'note'   => $stop['note'],
                    'site'   => [
                        'id'         => (int) $site->id,
                        'name'       => $site->name,
                        'mr_name'    => $site->mr_name,
                        'custom'     => (int) $site->id < 0,
                        'categories' => ((int) $site->id < 0
                            ? collect()
                            : $site->categories)->map(fn ($c) => [
                            'code' => $c->code,
                            'name' => $c->name,
                        ])->all(),
                        'latitude'   => $site->latitude,
                        'longitude'  => $site->longitude,
                        'image'      => $site->image,
                        'logo'       => $site->logo,
                        // Straight from the live row, never from the model.
                        'phone'      => $site->phone,
                        'whatsapp'   => $site->whatsapp,
                        'verified'   => $site->verification_status === 'verified',
                    ],
                ];
            }

            $days[] = [
                'day'   => $day['day'],
                'theme' => $day['theme'],
                'stops' => $stops,
                // Travel is resolved here, from route_stops — the model was
                // never asked for a bus time and never gets to state one.
                'legs'  => $this->legs->legsFor(collect($ordered)),
            ];
        }

        return [
            'trip' => [
                'title'   => (string) ($raw['trip']['title'] ?? 'Your trip'),
                'summary' => (string) ($raw['trip']['summary'] ?? ''),
                'days'    => count($days),
            ],
            'days'     => $days,
            'warnings' => array_values(array_filter(
                (array) ($raw['warnings'] ?? []),
                'is_string'
            )),
        ];
    }

    private function fail(array $dropped, string $reason): array
    {
        return [
            'ok'         => false,
            'itinerary'  => null,
            'dropped'    => $dropped,
            'reason'     => $reason,
            'sites_used' => 0,
        ];
    }

    /** Accept HH:MM only; anything else becomes null rather than reaching the app. */
    /**
     * Day one, moved to when the traveller is actually there.
     *
     * Everything is pushed forward by however late the arrival is, and
     * anything that no longer fits before the evening is dropped rather than
     * promised. A stop at 23:30 is not a stop.
     *
     * @param  array<int,array<string,mixed>>  $stops
     * @return array<int,array<string,mixed>>
     */
    private function afterArrival(array $stops, string $arrivalStart): array
    {
        if ($stops === []) {
            return $stops;
        }

        $first = $stops[0]['arrive'] ?? null;

        if ($first === null || $first >= $arrivalStart) {
            return $stops;
        }

        $shift = $this->minutes($arrivalStart) - $this->minutes($first);
        $lastUsefulHour = (int) AiConfig::get('day_ends_at_hour', 21) * 60;

        $moved = [];

        foreach ($stops as $stop) {
            $arrive = $this->shift($stop['arrive'] ?? null, $shift);

            if ($arrive === null || $this->minutes($arrive) > $lastUsefulHour) {
                break;
            }

            $stop['arrive'] = $arrive;
            $stop['depart'] = $this->shift($stop['depart'] ?? null, $shift);
            $moved[] = $stop;
        }

        return $moved;
    }

    /**
     * Complete any day the model left short, using places it was already given.
     *
     * Nearest-first, so a filled day stays geographically coherent rather than
     * sending the traveller across the district for the sake of a count. Stops
     * are appended after the day's last departure and never past the evening —
     * a stop at 23:30 is not a stop, the same rule arrival-shifting uses.
     *
     * @param  array<int,array<string,mixed>>  $clean
     * @param  array<int,int>                  $allowedIds
     * @param  array<int,int>                  $usedIds     grows as places are taken
     * @param  array<int,int>                  $requiredIds the traveller's own picks
     * @return array<int,array<string,mixed>>
     */
    private function fillThinDays(
        array $clean,
        array $allowedIds,
        array &$usedIds,
        array $requiredIds,
        int $minPerDay,
        ?string $arrivalStart,
    ): array {
        // Most plans come back whole. Decide that before touching the database,
        // or every good plan pays for a lookup only a short one needs.
        $short = false;

        foreach ($clean as $index => $day) {
            if (($arrivalStart === null || $index !== 0) && count($day['stops']) < $minPerDay) {
                $short = true;
                break;
            }
        }

        $spare = $short ? array_values(array_diff($allowedIds, $usedIds)) : [];

        if ($spare === []) {
            return $clean;
        }

        // One query for every coordinate this may need to measure against.
        $coords = Site::whereIn('id', array_merge($spare, $usedIds))
            ->get(['id', 'latitude', 'longitude'])
            ->keyBy('id');

        $dayEndsAt = (int) AiConfig::get('day_ends_at_hour', 21) * 60;

        foreach ($clean as $index => $day) {
            // Day one after a late arrival is exempt from the minimum, so it is
            // exempt from being padded to meet it.
            if ($arrivalStart !== null && $index === 0) {
                continue;
            }

            while (count($clean[$index]['stops']) < $minPerDay && $spare !== []) {
                $last   = $clean[$index]['stops'][count($clean[$index]['stops']) - 1];
                $anchor = $coords->get($last['site_id']);

                // A place the traveller picked and the model left out comes
                // first, wherever it is: their own choice outranks proximity,
                // and leaving it out would fail the plan a few lines below
                // anyway. Only then does the nearest suggestion get a look.
                $theirs = array_values(array_intersect($spare, $requiredIds));
                $pick   = $theirs !== []
                    ? $this->nearestTo($anchor, $theirs, $coords)
                    : $this->nearestTo($anchor, $spare, $coords);

                // Leave time to get there, then a stop long enough to be worth
                // making. Both are plain defaults — the model writes the times
                // it chooses, and this is only for the stops it declined to add.
                $arrive = $this->shift($last['depart'] ?? $last['arrive'], 45);

                if ($arrive === null || $this->minutes($arrive) > $dayEndsAt) {
                    break;
                }

                $clean[$index]['stops'][] = [
                    'site_id' => $pick,
                    'arrive'  => $arrive,
                    'depart'  => $this->shift($arrive, 90),
                    // No note: the model did not write one, and inventing prose
                    // about a place is exactly what this class exists to prevent.
                    'note'    => '',
                ];

                $usedIds[] = $pick;
                $spare     = array_values(array_diff($spare, [$pick]));
            }
        }

        return $clean;
    }

    /**
     * The spare place closest to where the day currently ends.
     *
     * Without coordinates on either side there is nothing to compare, so the
     * first spare is taken rather than pretending to have measured.
     *
     * @param  array<int,int>  $spare
     */
    private function nearestTo($anchor, array $spare, Collection $coords): int
    {
        if (!$anchor || $anchor->latitude === null || $anchor->longitude === null) {
            return $spare[0];
        }

        $best     = $spare[0];
        $bestDist = INF;

        foreach ($spare as $id) {
            $site = $coords->get($id);

            if (!$site || $site->latitude === null || $site->longitude === null) {
                continue;
            }

            $dist = $this->km(
                (float) $anchor->latitude, (float) $anchor->longitude,
                (float) $site->latitude, (float) $site->longitude,
            );

            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $id;
            }
        }

        return $best;
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;

        return $r * acos(min(1.0, max(-1.0,
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1))
            + sin(deg2rad($lat1)) * sin(deg2rad($lat2))
        )));
    }

    private function minutes(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    private function shift(?string $time, int $byMinutes): ?string
    {
        if ($time === null) {
            return null;
        }

        $total = $this->minutes($time) + $byMinutes;

        return $total >= 24 * 60
            ? null
            : sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private function time(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }
}
