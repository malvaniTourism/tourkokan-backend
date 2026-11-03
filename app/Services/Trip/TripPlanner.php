<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\AiItineraryCache;
use App\Models\AiLog;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\TripPlacement;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the pipeline: fetch, prompt, call, validate, rehydrate, log.
 *
 * The controller's whole job is to call one of these methods and hand the
 * result back, so the ordering rules live here rather than in HTTP code.
 */
class TripPlanner
{
    public function __construct(
        private CandidateFetcher $candidates,
        private LegResolver $legs,
        private ItineraryPrompt $prompts,
        private ItineraryAssembler $assembler,
        private AiClient $ai,
        private CreditLedger $credits,
        private TripCart $cart,
        private ItineraryCache $cache,
        private SpendGuard $spend,
    ) {
    }

    /**
     * Plan a trip from the places a traveller ticked.
     *
     * @return array{ok: bool, itinerary: array|null, reason: string|null, log_id: int|null}
     */
    public function planFromCart(SavedTrip $trip): array
    {
        $minCart = (int) AiConfig::get('min_cart_places', 3);

        // Through TripCart, so the plan follows the order the traveller
        // dragged the places into. Reading `favourites` directly here ordered
        // by id instead, which quietly ignored every reorder they made.
        $picked = $this->cart->contents($trip);

        // Pasted pins are picks, and they are planned a few lines below — so
        // they count here too. Counting only `favourites` made the app's Plan
        // button offer a trip this gate then refused, which is worse than the
        // button being disabled: the traveller had already been told "added
        // to your trip".
        $custom = $this->cart->customSites($trip);

        if ($picked->count() + $custom->count() < $minCart) {
            return $this->refuse('cart_too_small');
        }

        // The other end of the same rule. Every picked place is mandatory —
        // the assembler fails a plan that leaves one out — so more picks than
        // the days can hold is a trip no model can deliver. Refusing here
        // costs nothing; letting it through spends a credit on a call whose
        // only possible outcome is picked_place_missing, and tells the
        // traveller "that did not come out right" when the truth is "that is
        // more places than three days can hold".
        $days     = max(1, (int) $trip->days);
        $capacity = $days * (int) AiConfig::get('max_stops_per_day', 5);

        if ($picked->count() + $custom->count() > $capacity) {
            return $this->refuse('cart_too_big');
        }

        $user = $trip->user;

        // One credit buys one trip, re-plans included — the ledger keys the
        // spend by trip, so this is free the second time round.
        if (!$this->credits->spend($user, (int) $trip->id)) {
            return $this->refuse('insufficient_credits');
        }

        $filters = (array) $trip->filters;

        // Fill the gaps around what they chose. The towns come from the
        // stepper's step 1 when it saved them; otherwise this falls back to
        // the base town, or to the first place picked.
        //
        // Both paths go through `aroundMany` so a cart trip and a form trip
        // are filled from the same query — and so a traveller who chose three
        // towns gets places near all three, not three towns' worth of
        // candidates crowded out by whichever one happened to be the anchor.
        $anchors = $this->candidates->townsByIds(
            array_map('intval', (array) data_get($filters, 'town_site_ids', []))
        );

        if ($anchors->isEmpty()) {
            $fallback = $trip->baseSite ?: ($picked->first() ?: $custom->first());
            $anchors  = $fallback ? collect([$fallback]) : collect();
        }

        $suggested = $this->candidates->aroundMany(
            $anchors,
            (array) data_get($filters, 'category_codes', []),
            (float) data_get($filters, 'radius_km', 40),
            $picked->pluck('id')->all(),
        );

        // Arrival was being stored and then ignored on this path: it is
        // resolved on the form path only, so a stepper trip would carry a
        // train time that never reached the plan.
        $arrival = $this->resolveArrival($filters, $anchors);

        $result = $this->run(
            picked: $picked->concat($custom),
            suggested: $suggested,
            days: $days,
            userId: (int) $trip->user_id,
            tripId: (int) $trip->id,
            type: 'cart',
            filters: $filters,
            arrival: $arrival,
            extraSites: $custom,
        );

        if (!$result['ok']) {
            // A failure must never cost someone a plan. They see a retry, not
            // a smaller balance.
            $this->credits->refund($user, (int) $trip->id);

            return $result;
        }

        $this->persist($trip, $result['itinerary']);

        return $result;
    }

    /**
     * What a filter set would give you, without calling the model.
     *
     * Free, and the only honest way to answer "can this be planned" before a
     * credit is spent. `planFromForm` runs the same resolution through the same
     * floor check, so a quote that says yes cannot be followed by a plan that
     * refuses for want of places.
     *
     * @return array{ok: bool, reason: string|null, candidates: int, needed: int, days: int, towns: array<int,string>}
     */
    public function quote(array $filters): array
    {
        $set = $this->resolveFilters($filters);

        return [
            'ok'         => $set['ok'],
            'reason'     => $set['reason'],
            'candidates' => $set['total'],
            'needed'     => $set['needed'],
            'days'       => $set['days'],
            'towns'      => $set['anchors']->pluck('name')->all(),
            // Free, and the question a traveller cannot answer themselves:
            // can I actually get from where I land to where I am going?
            'arrival'    => $set['arrival'],
        ];
    }

    /**
     * Plan from the form's filters, with nothing ticked beforehand.
     *
     * The trip row is created before the spend, because the ledger keys a
     * credit to a trip — that is what makes a re-plan free. A refusal after
     * that point deletes the row again: a `draft` with no places in it would
     * become the traveller's open cart and hide the one they were filling.
     *
     * @return array{ok: bool, itinerary: array|null, reason: string|null, log_id: int|null, trip_id: int|null}
     */
    public function planFromForm(User $user, array $filters): array
    {
        $set = $this->resolveFilters($filters);

        if (!$set['ok']) {
            return $this->refuse($set['reason']) + ['trip_id' => null];
        }

        $trip = SavedTrip::create([
            'user_id'      => $user->id,
            'status'       => 'draft',
            'days'         => $set['days'],
            'base_site_id' => $set['anchors']->first()->id,
            'filters'      => $set['filters'],
            'title'        => $filters['title'] ?? null,
        ]);

        if (!$this->credits->spend($user, (int) $trip->id)) {
            $trip->delete();

            return $this->refuse('insufficient_credits') + ['trip_id' => null];
        }

        $result = $this->run(
            picked: $set['picked'],
            suggested: $set['suggested'],
            days: $set['days'],
            userId: (int) $user->id,
            tripId: (int) $trip->id,
            type: 'plan',
            filters: $set['filters'],
            cacheKey: $this->cacheKeyFor($set),
            arrival: $set['arrival'],
        );

        if (!$result['ok']) {
            $this->credits->refund($user, (int) $trip->id);
            $trip->delete();

            return $result + ['trip_id' => null];
        }

        $this->persist($trip, $result['itinerary']);

        return $result + ['trip_id' => (int) $trip->id];
    }

    /**
     * The cache key for a resolved filter set, or null where caching would be
     * wrong.
     *
     * A trip with places the traveller has already committed to — a booked
     * stay, or a whole cart — has an input set that is theirs alone. Two
     * travellers with the same filters and different hotels would share a key,
     * each find the other's answer missing their own stay, and take turns
     * throwing it away. Those plans are also the ones travellers value most,
     * so paying for them is the right trade.
     */
    private function cacheKeyFor(array $set): ?string
    {
        if ($set['picked']->isNotEmpty()) {
            return null;
        }

        $anchor = $set['anchors']->first();

        return $this->cache->key(
            categoryCodes: $set['filters']['category_codes'],
            days: $set['days'],
            lat: (float) $anchor->latitude,
            lng: (float) $anchor->longitude,
            radiusKm: (float) $set['filters']['radius_km'],
            stay: (string) $set['filters']['stay'],
            arrival: $set['arrival'],
        );
    }

    /**
     * Turn a filter set into the places it reaches, and say whether that is
     * enough. Shared by `quote` and `planFromForm` so the two cannot drift.
     *
     * @return array{
     *     ok: bool, reason: string|null, days: int, needed: int, total: int,
     *     anchors: \Illuminate\Support\Collection, picked: \Illuminate\Support\Collection,
     *     suggested: \Illuminate\Support\Collection, filters: array
     * }
     */
    private function resolveFilters(array $filters): array
    {
        $maxDays = (int) AiConfig::get('max_days', 7);
        $days    = max(1, min($maxDays, (int) ($filters['days'] ?? 2)));
        $radius  = (float) ($filters['radius_km'] ?? AiConfig::get('default_radius_km', 40));
        $needed  = $days * (int) AiConfig::get('min_stops_per_day', 2);

        $empty = collect();
        // Anchors go through the town rule, not the destination one: a taluka
        // town may also be a bus depot, and the question "can this anchor a
        // trip" is not the question "is this worth visiting".
        $anchors = $this->candidates->townsByIds(
            array_map('intval', (array) ($filters['town_site_ids'] ?? []))
        );

        if ($anchors->isEmpty()) {
            return [
                'ok' => false, 'reason' => 'town_not_found', 'days' => $days,
                'needed' => $needed, 'total' => 0, 'anchors' => $empty,
                'picked' => $empty, 'suggested' => $empty, 'filters' => $filters,
            ];
        }

        $codes = array_values(array_unique(array_filter(
            (array) ($filters['category_codes'] ?? [])
        )));

        // "Suggest me somewhere to stay" is a wider candidate set, not a
        // separate search: the model places a stay from the same list.
        if (($filters['stay'] ?? 'suggest') === 'suggest' && $codes !== []) {
            $codes = array_values(array_unique(array_merge(
                $codes,
                $this->candidates->childCodes('accomodation')
            )));
        }

        // A stay they have already booked is treated exactly like a place they
        // ticked: it must survive validation or the plan fails.
        $stayId = (int) ($filters['stay_site_id'] ?? 0);
        $picked = ($filters['stay'] ?? null) === 'booked' && $stayId > 0
            ? $this->candidates->byIds([$stayId])
            : $empty;

        $suggested = $this->candidates->aroundMany(
            $anchors,
            $codes,
            $radius,
            $picked->pluck('id')->all(),
        );

        $arrival = $this->resolveArrival($filters, $anchors);

        $total = $picked->count() + $suggested->count();

        return [
            'ok'        => $total >= $needed,
            'reason'    => $total >= $needed ? null : 'not_enough_places',
            'days'      => $days,
            'needed'    => $needed,
            'total'     => $total,
            'anchors'   => $anchors,
            'picked'    => $picked,
            'suggested' => $suggested,
            'arrival'   => $arrival,
            'filters'   => [
                'town_site_ids'  => $anchors->pluck('id')->map(fn ($v) => (int) $v)->all(),
                'category_codes' => $codes,
                'radius_km'      => $radius,
                'stay'           => $filters['stay'] ?? 'suggest',
                'stay_site_id'   => $picked->isNotEmpty() ? (int) $picked->first()->id : null,
                'arrival'        => $arrival === null ? null : [
                    'mode'    => $arrival['mode'],
                    'site_id' => $arrival['site_id'],
                    'at'      => $arrival['at'],
                ],
                'departure_at'   => $filters['departure_at'] ?? null,
            ],
        ];
    }

    /**
     * How the traveller gets here, as facts the plan can be shaped around.
     *
     * Driving needs no entry point — they arrive at the first stop. Everything
     * else lands somewhere specific, and the question that matters is not
     * whether a bus exists but whether one runs *after* they land: a 06:10
     * service is no use to an afternoon train.
     *
     * Returns null when there is nothing useful to say, so the rest of the
     * pipeline is unchanged for the traveller who does not answer.
     *
     * @param  \Illuminate\Support\Collection  $anchors
     * @return array<string,mixed>|null
     */
    private function resolveArrival(array $filters, $anchors): ?array
    {
        $mode = $filters['arrival']['mode'] ?? null;

        if ($mode === null) {
            return null;
        }

        $at = $filters['arrival']['at'] ?? null;
        $point = $this->candidates->arrivalPoint(
            (int) ($filters['arrival']['site_id'] ?? 0)
        );

        // Own vehicle: no station, no onward bus, just the hour they get in.
        if ($mode === 'own_vehicle' || $point === null) {
            return [
                'mode'           => $mode,
                'site_id'        => $point?->id,
                'from'           => $point?->name,
                'at'             => $at,
                'earliest_start' => $this->earliestStart($at),
                'reaches'        => [],
                'onward'         => null,
                'last_day_ends_by' => $filters['departure_at'] ?? null,
            ];
        }

        $townIds = $anchors->pluck('id')->map(fn ($v) => (int) $v)->all();
        $reaches = $this->legs->reachableTowns((int) $point->id, $townIds);

        // The onward connection to the first town they can actually get to.
        $firstReachable = $reaches[0] ?? ($townIds[0] ?? null);
        $onward = $firstReachable
            ? $this->legs->onwardFrom((int) $point->id, (int) $firstReachable, $at)
            : null;

        // The day cannot start before they are actually in town. Landing at
        // 13:40 means nothing if the only onward bus goes at 17:20 — planning
        // a 14:40 first stop would strand them at the station watching it.
        $start = $this->earliestStart($at);

        if ($onward && !empty($onward['arrive']) && empty($onward['tomorrow'])) {
            // Half an hour off the bus, not a full hour: they are already
            // in town by then.
            $start = max($start, $this->earliestStart($onward['arrive'], 30));
        }

        return [
            'mode'           => $mode,
            'site_id'        => (int) $point->id,
            'from'           => $point->name,
            'at'             => $at,
            'earliest_start' => $start,
            'reaches'        => $reaches,
            'onward'         => $onward,
            'last_day_ends_by' => $filters['departure_at'] ?? null,
        ];
    }

    /**
     * The hour the trip can actually begin.
     *
     * An hour after landing, because nobody steps off a train and straight
     * onto a beach. Before 09:00 it makes no difference — the day was always
     * going to start then.
     */
    private function earliestStart(?string $at, int $bufferMinutes = 60): string
    {
        if (!$at) {
            return '09:00';
        }

        $minutes = ((int) substr($at, 0, 2)) * 60 + (int) substr($at, 3, 2) + $bufferMinutes;
        $minutes = min($minutes, 23 * 60);

        $start = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        return max($start, '09:00');
    }

    /**
     * Save the plan and record who appeared in it.
     *
     * The placement rows are the entire vendor sales conversation later on
     * ("your homestay appeared in 40 itineraries"), and they cannot be
     * back-filled, which is why they are written here with the first itinerary
     * rather than added when the vendor dashboard is built.
     */
    private function persist(SavedTrip $trip, array $itinerary): void
    {
        $trip->forceFill([
            'status'    => 'generated',
            'itinerary' => $itinerary,
            'title'     => $trip->title ?: ($itinerary['trip']['title'] ?? null),
        ])->save();

        // Re-planning replaces the previous appearances rather than stacking
        // them, so a traveller regenerating five times does not read as five
        // separate exposures for every business involved.
        TripPlacement::where('trip_id', $trip->id)->delete();

        $rows = [];
        $now  = now();

        foreach ($itinerary['days'] as $day) {
            foreach (array_values($day['stops']) as $position => $stop) {
                // Negative ids are the traveller's own pins. No business sits
                // behind one, so there is no exposure to record — and no row
                // for the foreign key to point at.
                if ((int) $stop['site']['id'] < 0) {
                    continue;
                }

                $rows[] = [
                    'site_id'    => (int) $stop['site']['id'],
                    'trip_id'    => (int) $trip->id,
                    'user_id'    => (int) $trip->user_id,
                    'day'        => (int) $day['day'],
                    'position'   => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            TripPlacement::insert($rows);
        }
    }

    /**
     * The shared body: everything after the places are known.
     */

    private function run(
        $picked,
        $suggested,
        int $days,
        ?int $userId,
        ?int $tripId,
        string $type,
        array $filters,
        ?string $cacheKey = null,
        ?array $arrival = null,
        $extraSites = null,
    ): array {
        $pickedRows    = $this->candidates->project($picked);
        $suggestedRows = $this->candidates->project($suggested);

        $prompt = $this->prompts->build(
            picked: $pickedRows,
            suggested: $suggestedRows,
            legs: $this->legs->legsFor($picked),
            days: $days,
            arrival: $arrival,
        );

        $log = [
            'user_id'         => $userId,
            'trip_id'         => $tripId,
            'type'            => $type,
            'input_filters'   => $filters,
            'candidates_sent' => count($prompt['allowed_site_ids']),
            'prompt_hash'     => $this->prompts->hash($prompt),
        ];

        $requiredIds = $picked->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Somebody has asked this before. Checked before the ceiling, so an
        // already-answered question keeps working even when spending has
        // stopped for the day.
        if ($cacheKey !== null && $entry = $this->cache->get($cacheKey)) {
            $served = $this->serveCached($entry, $prompt, $days, $requiredIds, $log, $arrival);

            if ($served !== null) {
                return $served;
            }
        }

        // The circuit breaker. A new question is what costs money, so this is
        // the only place it can be refused.
        if (!$this->spend->canSpend()) {
            Log::warning('[trip] daily spend ceiling reached', [
                'spent'   => $this->spend->spentToday(),
                'ceiling' => $this->spend->ceiling(),
            ]);

            $this->write($log + [
                'status'         => 'failed',
                'failure_reason' => 'spend_ceiling',
            ]);

            return $this->refuse('spend_ceiling');
        }

        try {
            $answer = $this->ai->itinerary($prompt, ItinerarySchema::json());
        } catch (Throwable $e) {
            Log::error('[trip] model call failed', ['message' => $e->getMessage()]);

            $this->write($log + [
                'status'         => 'failed',
                'failure_reason' => 'model_error',
                'error_message'  => $e->getMessage(),
                'error_trace'    => $e->getTraceAsString(),
            ]);

            return $this->refuse('generation_failed');
        }

        $meta = $answer['meta'] ?? [];
        $log += [
            'driver'     => $meta['driver'] ?? null,
            'model'      => $meta['model'] ?? null,
            'tokens_in'  => (int) ($meta['tokens_in'] ?? 0),
            'tokens_out' => (int) ($meta['tokens_out'] ?? 0),
            // Zero today: our frozen prefix is about 217 tokens and the model
            // will not cache one below 512. Recorded so that stops being an
            // assumption the day the prompt or the model changes.
            'cache_read_tokens'  => (int) ($meta['cache_read_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($meta['cache_write_tokens'] ?? 0),
            'cost_inr'   => $meta['cost_inr'] ?? 0,
            'latency_ms' => (int) ($meta['latency_ms'] ?? 0),
            'ai_response' => $answer['data'] ?? null,
        ];

        $result = $this->assembler->assemble(
            raw: $answer['data'] ?? [],
            allowedSiteIds: $prompt['allowed_site_ids'],
            days: $days,
            requiredSiteIds: $requiredIds,
            arrivalStart: $arrival['earliest_start'] ?? null,
            extraSites: $extraSites,
        );

        $row = $this->write($log + [
            'status'           => $result['ok'] ? 'success' : 'failed',
            'failure_reason'   => $result['reason'],
            'dropped_site_ids' => $result['dropped'],
            'valid_sites_used' => $result['sites_used'],
            'days_generated'   => $result['ok'] ? count($result['itinerary']['days']) : 0,
        ]);

        if (!$result['ok']) {
            return $this->refuse('generation_failed', $row?->id);
        }

        // Keep the answer, not the itinerary: the next traveller gets today's
        // places arranged the way this one's were.
        if ($cacheKey !== null) {
            $this->cache->put(
                hash: $cacheKey,
                response: $answer['data'] ?? [],
                siteIds: $this->siteIdsIn($result['itinerary']),
                days: $days,
                costInr: (float) ($meta['cost_inr'] ?? 0),
            );
        }

        return [
            'ok'        => true,
            'itinerary' => $this->withArrival($result['itinerary'], $arrival),
            'reason'    => null,
            'log_id'    => $row?->id,
        ];
    }

    /**
     * The journey in, attached to the finished plan.
     *
     * Kept out of the cached response body on purpose: the arrival is part of
     * the key, but the onward bus is read fresh so a cached plan never names a
     * service that has since been withdrawn.
     */
    private function withArrival(array $itinerary, ?array $arrival): array
    {
        if ($arrival !== null) {
            $itinerary['arrival'] = $arrival;
        }

        return $itinerary;
    }

    /**
     * Serve a cached answer, or return null to say it no longer holds.
     *
     * The answer goes through the same validator as a fresh one, against the
     * places that exist right now. A business that has closed since is dropped
     * exactly as an invented one would be — and if that leaves the plan too
     * thin, the entry is thrown away rather than served. This is why a cache
     * hit cannot be staler than a fresh generation.
     *
     * @param  array<int,int>  $requiredIds
     */
    private function serveCached(
        AiItineraryCache $entry,
        array $prompt,
        int $days,
        array $requiredIds,
        array $log,
        ?array $arrival = null
    ): ?array {
        $started = microtime(true);

        $result = $this->assembler->assemble(
            raw: (array) $entry->response,
            allowedSiteIds: $prompt['allowed_site_ids'],
            days: $days,
            requiredSiteIds: $requiredIds,
            arrivalStart: $arrival['earliest_start'] ?? null,
        );

        if (!$result['ok']) {
            $this->cache->forget($entry->filter_hash);

            return null;
        }

        $this->cache->hit($entry);

        $row = $this->write($log + [
            'status'           => 'cached',
            'driver'           => 'cache',
            // Nothing was bought, which is the whole point of the row.
            'cost_inr'         => 0,
            'latency_ms'       => (int) round((microtime(true) - $started) * 1000),
            'ai_response'      => $entry->response,
            'dropped_site_ids' => $result['dropped'],
            'valid_sites_used' => $result['sites_used'],
            'days_generated'   => count($result['itinerary']['days']),
        ]);

        return [
            'ok'        => true,
            'itinerary' => $this->withArrival($result['itinerary'], $arrival),
            'reason'    => null,
            'log_id'    => $row?->id,
        ];
    }

    /**
     * Every place a finished plan names.
     *
     * @return array<int,int>
     */
    private function siteIdsIn(array $itinerary): array
    {
        return collect($itinerary['days'] ?? [])
            ->flatMap(fn ($day) => collect($day['stops'] ?? [])->pluck('site.id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function write(array $attributes): ?AiLog
    {
        try {
            return AiLog::create($attributes);
        } catch (Throwable $e) {
            // A logging failure must never cost the traveller their itinerary.
            Log::error('[trip] could not write ai_log', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function refuse(string $reason, ?int $logId = null): array
    {
        return ['ok' => false, 'itinerary' => null, 'reason' => $reason, 'log_id' => $logId];
    }
}
