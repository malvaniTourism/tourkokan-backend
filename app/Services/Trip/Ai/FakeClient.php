<?php

namespace App\Services\Trip\Ai;

use App\Models\AiConfig;

/**
 * A deterministic stand-in for the model.
 *
 * It does the real job badly but correctly: picked places first, then nearby
 * suggestions, spread across the days without exceeding the per-day cap. Same
 * input always gives the same plan, so the whole pipeline — validation, the
 * assembler, the controller, the tests — runs in CI with no API key, no network
 * and no spend.
 *
 * It deliberately never invents a site_id, so a test that wants to prove the
 * assembler drops strangers injects one itself rather than hoping for a
 * hallucination.
 */
class FakeClient implements AiClient
{
    public function itinerary(array $prompt, array $schema): array
    {
        $started = microtime(true);

        $payload   = $prompt['payload'] ?? [];
        $days      = max(1, (int) ($prompt['days'] ?? 1));
        $maxPerDay = (int) AiConfig::get('max_stops_per_day', 5);

        // Picked first — the traveller's own choices must all appear.
        $ordered = array_merge(
            $payload['picked'] ?? [],
            $payload['suggested'] ?? []
        );

        $capacity = $days * $maxPerDay;
        $ordered  = array_slice($ordered, 0, $capacity);

        $buckets = array_fill(0, $days, []);
        foreach ($ordered as $i => $place) {
            $buckets[$i % $days][] = $place;
        }

        $clock = ['09:00', '11:00', '13:00', '15:30', '17:30'];

        $out = [];
        foreach ($buckets as $index => $places) {
            if ($places === []) {
                continue;
            }

            $stops = [];
            foreach (array_values($places) as $n => $place) {
                $arrive = $clock[$n] ?? '18:00';
                $depart = $clock[$n + 1] ?? '19:00';

                $stops[] = [
                    'site_id' => (int) $place['site_id'],
                    'arrive'  => $arrive,
                    'depart'  => $depart,
                    'note'    => 'Planned stop at ' . $place['name'] . '.',
                ];
            }

            $out[] = [
                'day'   => $index + 1,
                'theme' => 'Day ' . ($index + 1) . ' around the coast',
                'stops' => $stops,
            ];
        }

        return [
            'data' => [
                'trip' => [
                    'title'   => $days . ($days === 1 ? ' day' : ' days') . ' in Konkan',
                    'summary' => 'A sample plan built without calling a model.',
                ],
                'days'     => $out,
                'warnings' => [],
            ],
            'meta' => [
                'driver'     => 'fake',
                'model'      => 'fake',
                'tokens_in'  => 0,
                'tokens_out' => 0,
                'cost_inr'   => 0,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        ];
    }
}
