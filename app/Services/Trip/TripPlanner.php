<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\AiLog;
use App\Models\SavedTrip;
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

        if ($picked->count() < $minCart) {
            return $this->refuse('cart_too_small');
        }

        $days = max(1, (int) $trip->days);
        $user = $trip->user;

        // One credit buys one trip, re-plans included — the ledger keys the
        // spend by trip, so this is free the second time round.
        if (!$this->credits->spend($user, (int) $trip->id)) {
            return $this->refuse('insufficient_credits');
        }

        // Fill the gaps around what they chose, anchored on their base town if
        // they named one, otherwise on the first place they picked.
        $anchor     = $trip->baseSite ?: $picked->first();
        $suggested  = $this->candidates->nearby(
            (float) $anchor->latitude,
            (float) $anchor->longitude,
            (array) data_get($trip->filters, 'category_codes', []),
            (float) data_get($trip->filters, 'radius_km', 40),
            $picked->pluck('id')->all(),
        );

        $result = $this->run(
            picked: $picked,
            suggested: $suggested,
            days: $days,
            userId: (int) $trip->user_id,
            tripId: (int) $trip->id,
            type: 'cart',
            filters: (array) $trip->filters,
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
        $anchors = $this->candidates->byIds(
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
            'filters'   => [
                'town_site_ids'  => $anchors->pluck('id')->map(fn ($v) => (int) $v)->all(),
                'category_codes' => $codes,
                'radius_km'      => $radius,
                'stay'           => $filters['stay'] ?? 'suggest',
                'stay_site_id'   => $picked->isNotEmpty() ? (int) $picked->first()->id : null,
            ],
        ];
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
    ): array {
        $pickedRows    = $this->candidates->project($picked);
        $suggestedRows = $this->candidates->project($suggested);

        $prompt = $this->prompts->build(
            picked: $pickedRows,
            suggested: $suggestedRows,
            legs: $this->legs->legsFor($picked),
            days: $days,
        );

        $log = [
            'user_id'         => $userId,
            'trip_id'         => $tripId,
            'type'            => $type,
            'input_filters'   => $filters,
            'candidates_sent' => count($prompt['allowed_site_ids']),
            'prompt_hash'     => $this->prompts->hash($prompt),
        ];

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
            'cost_inr'   => $meta['cost_inr'] ?? 0,
            'latency_ms' => (int) ($meta['latency_ms'] ?? 0),
            'ai_response' => $answer['data'] ?? null,
        ];

        $result = $this->assembler->assemble(
            raw: $answer['data'] ?? [],
            allowedSiteIds: $prompt['allowed_site_ids'],
            days: $days,
            requiredSiteIds: $picked->pluck('id')->map(fn ($v) => (int) $v)->all(),
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

        return [
            'ok'        => true,
            'itinerary' => $result['itinerary'],
            'reason'    => null,
            'log_id'    => $row?->id,
        ];
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
