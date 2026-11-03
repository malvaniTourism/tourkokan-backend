<?php

namespace App\Services\Trip\Ai;

use Anthropic\Client;
use App\Models\AiConfig;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The real driver.
 *
 * Uses structured outputs, so the response is guaranteed to match
 * `ItinerarySchema` rather than being coaxed into shape by the prompt. That is
 * the whole reason the fixed JSON format is not a prompt-engineering problem
 * here: the schema is enforced by the API.
 *
 * The key is read from the environment on the server and never travels to the
 * app or the admin panel — a key inside an APK can be extracted in minutes.
 */
class ClaudeClient implements AiClient
{
    /**
     * Published per-million-token prices in USD. Kept in code rather than in
     * ai_configs because they are facts about the provider, not something an
     * operator should be editing during an incident. A model missing from here
     * logs a warning and records zero cost rather than inventing a number.
     */
    private const USD_PER_MTOK = [
        'claude-haiku-5-5'  => ['in' => 0.10, 'out' => 0.50],
        'claude-sonnet-5-5' => ['in' => 2.00, 'out' => 10.00],
        'claude-opus-5-5'   => ['in' => 4.00, 'out' => 20.00],
    ];

    /**
     * Cached input against the base input price. A read is a tenth, a write a
     * quarter more. (Opus 5.5 reads cheaper still, at 0.05x — ignored here
     * because it would only ever understate a bill we are not running.)
     */
    private const CACHE_READ_MULTIPLIER  = 0.1;
    private const CACHE_WRITE_MULTIPLIER = 1.25;

    /**
     * The shortest prefix Claude Haiku 5.5 will cache. A shorter one is not an
     * error: it silently caches nothing and reports zero cache tokens.
     */
    private const MIN_CACHEABLE_TOKENS = 512;

    public function __construct(private ?Client $client = null)
    {
    }

    public function itinerary(array $prompt, array $schema): array
    {
        $started = microtime(true);
        $model   = (string) AiConfig::get('ai_model', 'claude-haiku-5-5');

        $message = $this->client()->messages->create(
            maxTokens: 8000,
            messages: [['role' => 'user', 'content' => $prompt['user']]],
            model: $model,
            outputConfig: [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
                // Arranging pre-fetched, pre-validated rows is not hard
                // reasoning. Low effort keeps the bill where it belongs.
                'effort' => 'low',
            ],
            // The frozen half of the prompt goes here; the volatile trip data
            // rides in the user turn, which is the order caching requires.
            system: $this->cacheable($prompt['system']),
        );

        // READ THE BILL FIRST. Every failure below arrives as a billed HTTP
        // 200, and throwing before this point recorded them all as costing
        // nothing — which left the daily spend ceiling blind to precisely the
        // loop it exists to stop: a prompt or schema change that makes the
        // model fail every call, each one paid for.
        $tokensIn  = (int) $message->usage->inputTokens;
        $tokensOut = (int) $message->usage->outputTokens;
        // Zero unless the frozen prefix is both marked cacheable and long
        // enough to qualify — see `cacheable()` below. Read regardless, so the
        // logs answer the question instead of us assuming it.
        $cacheRead  = (int) ($message->usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($message->usage->cacheCreationInputTokens ?? 0);

        $meta = [
            'driver'             => 'claude',
            'model'              => $message->model ?: $model,
            'tokens_in'          => $tokensIn,
            'tokens_out'         => $tokensOut,
            'cache_read_tokens'  => $cacheRead,
            'cache_write_tokens' => $cacheWrite,
            'cost_inr'           => $this->costInr(
                $message->model ?: $model,
                $tokensIn,
                $tokensOut,
                $cacheRead,
                $cacheWrite
            ),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        // Safety classifiers can decline with HTTP 200, so the stop reason is
        // checked before the content is read.
        if ($message->stopReason === 'refusal') {
            throw new BilledFailure('The model declined this request.', $meta);
        }

        $text = $this->firstText($message->content);

        if ($text === null) {
            throw new BilledFailure('The model returned no text block.', $meta);
        }

        $data = json_decode($text, true);

        if (!is_array($data)) {
            throw new BilledFailure('The model returned text that is not JSON.', $meta);
        }

        return [
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * The system prompt, marked cacheable only if it is long enough to be.
     *
     * Our frozen prefix is currently around 217 tokens, well under the 512 the
     * model needs, so a marker on it would cache nothing and say nothing about
     * it. Rather than ship a no-op or pretend, the decision is made from the
     * prompt's actual size: grow the prompt past the threshold, or move to a
     * model with a different one, and caching starts on its own — with the
     * logged cache tokens as the proof.
     *
     * @return array<int,array<string,mixed>>|string
     */
    private function cacheable(string $system): array|string
    {
        // Roughly 3.6 characters per token for English prose. A crude estimate
        // is enough: being wrong either way only costs a marker that does
        // nothing, which is what we already have.
        if ((int) (strlen($system) / 3.6) < self::MIN_CACHEABLE_TOKENS) {
            return $system;
        }

        return [[
            'type'         => 'text',
            'text'         => $system,
            'cacheControl' => ['type' => 'ephemeral'],
        ]];
    }

    /** Structured outputs put the JSON in the first text block. */
    private function firstText(array $content): ?string
    {
        foreach ($content as $block) {
            if (($block->type ?? null) === 'text' && isset($block->text)) {
                return $block->text;
            }
        }

        return null;
    }

    /**
     * What the call cost, in rupees.
     *
     * Cached input is not priced like fresh input: a read costs a tenth of an
     * input token and a write costs a quarter more than one. Counting either as
     * ordinary input would misreport the bill — and the bill is what the daily
     * ceiling is measured against.
     */
    private function costInr(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheRead = 0,
        int $cacheWrite = 0
    ): float {
        $rates = self::USD_PER_MTOK[$model] ?? null;

        if ($rates === null) {
            Log::warning('[trip] no published price for model, recording zero cost', [
                'model' => $model,
            ]);

            return 0.0;
        }

        $perMtok = fn (int $tokens, float $rate) => $tokens / 1_000_000 * $rate;

        $usd = $perMtok($tokensIn, $rates['in'])
             + $perMtok($tokensOut, $rates['out'])
             + $perMtok($cacheRead, $rates['in'] * self::CACHE_READ_MULTIPLIER)
             + $perMtok($cacheWrite, $rates['in'] * self::CACHE_WRITE_MULTIPLIER);

        return round($usd * (float) AiConfig::get('usd_inr', 88), 4);
    }

    private function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $key = config('services.ai.key');

        if (empty($key)) {
            // Better to say this plainly than to fail deep inside the SDK with
            // an auth error that looks like an outage.
            throw new RuntimeException(
                'ANTHROPIC_API_KEY is not set. Set it on the server, or leave '
                . 'ai_driver on "fake".'
            );
        }

        return $this->client = new Client(apiKey: $key);
    }
}
