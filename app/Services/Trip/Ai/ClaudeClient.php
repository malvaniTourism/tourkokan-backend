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
            // The frozen half of the prompt goes here so it caches cleanly;
            // the volatile trip data rides in the user turn.
            system: $prompt['system'],
        );

        // Safety classifiers can decline with HTTP 200, so the stop reason is
        // checked before the content is read.
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The model declined this request.');
        }

        $text = $this->firstText($message->content);

        if ($text === null) {
            throw new RuntimeException('The model returned no text block.');
        }

        $data = json_decode($text, true);

        if (!is_array($data)) {
            throw new RuntimeException('The model returned text that is not JSON.');
        }

        $tokensIn  = (int) $message->usage->inputTokens;
        $tokensOut = (int) $message->usage->outputTokens;

        return [
            'data' => $data,
            'meta' => [
                'driver'     => 'claude',
                'model'      => $message->model ?: $model,
                'tokens_in'  => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_inr'   => $this->costInr($message->model ?: $model, $tokensIn, $tokensOut),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        ];
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

    private function costInr(string $model, int $tokensIn, int $tokensOut): float
    {
        $rates = self::USD_PER_MTOK[$model] ?? null;

        if ($rates === null) {
            Log::warning('[trip] no published price for model, recording zero cost', [
                'model' => $model,
            ]);

            return 0.0;
        }

        $usd = ($tokensIn / 1_000_000 * $rates['in'])
             + ($tokensOut / 1_000_000 * $rates['out']);

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
