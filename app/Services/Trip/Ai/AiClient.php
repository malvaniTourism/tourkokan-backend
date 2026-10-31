<?php

namespace App\Services\Trip\Ai;

/**
 * The one place the pipeline talks to a model.
 *
 * Kept behind an interface so the whole feature is testable with no API key and
 * no spend: `AI_DRIVER=fake` swaps in a deterministic implementation and every
 * other component behaves identically.
 */
interface AiClient
{
    /**
     * @param  array<string,mixed>  $prompt  from ItineraryPrompt::build()
     * @param  array<string,mixed>  $schema  from ItinerarySchema::json()
     *
     * @return array{data: array<string,mixed>, meta: array<string,mixed>}
     *         `data` conforms to $schema. `meta` carries driver, model,
     *         tokens_in, tokens_out, cost_inr and latency_ms for ai_logs.
     */
    public function itinerary(array $prompt, array $schema): array;
}
