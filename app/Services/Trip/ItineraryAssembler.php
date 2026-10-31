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
        array $requiredSiteIds = []
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

        foreach ($clean as $day) {
            if (count($day['stops']) < $minPerDay) {
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
            'itinerary'  => $this->rehydrate($raw, $clean),
            'dropped'    => $dropped,
            'reason'     => null,
            'sites_used' => count($usedIds),
        ];
    }

    /**
     * Rebuild the plan from our own rows. The model's ordering and prose are
     * kept; every fact is re-read from the database.
     */
    private function rehydrate(array $raw, array $clean): array
    {
        $ids = collect($clean)->flatMap(fn ($d) => array_column($d['stops'], 'site_id'))->all();

        /** @var Collection<int,Site> $sites */
        $sites = Site::whereIn('id', $ids)
            ->with(['categories:id,name,code'])
            ->get()
            ->keyBy('id');

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
                        'categories' => $site->categories->map(fn ($c) => [
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
    private function time(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }
}
