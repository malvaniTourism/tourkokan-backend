<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Services\Trip\Ai\AiClient;
use App\Services\Trip\Ai\ClaudeClient;
use App\Services\Trip\Ai\FakeClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\ApiTestCase;

/**
 * Which driver the container hands out, and how the real one fails.
 *
 * The call to Anthropic itself is not exercised here: it needs a key and costs
 * money, so it is proven against the live API once rather than on every CI
 * run. What is proven here is everything that decides whether that call
 * happens at all — the direction a mistake sends you.
 */
class TripAiDriverTest extends ApiTestCase
{
    private function driverBecomes(string $value): AiClient
    {
        AiConfig::updateOrCreate(['key' => 'ai_driver'], ['value' => $value]);

        // AiConfig caches reads for an hour, which is right in production and
        // wrong mid-test. The binding itself is per-resolve, so a flush is all
        // that is needed — refreshing the application would roll back the row
        // this test just wrote.
        Cache::flush();

        return app(AiClient::class);
    }

    public function test_the_default_driver_cannot_spend_money(): void
    {
        $this->assertInstanceOf(
            FakeClient::class,
            app(AiClient::class),
            'A fresh checkout must not be able to bill anyone'
        );
    }

    public function test_an_admin_can_switch_to_the_real_driver_without_a_deploy(): void
    {
        $this->assertInstanceOf(ClaudeClient::class, $this->driverBecomes('claude'));
    }

    public function test_an_unrecognised_driver_falls_back_to_the_fake_one(): void
    {
        // A typo in a config row must fail towards "free", never towards an
        // unbounded bill.
        $this->assertInstanceOf(FakeClient::class, $this->driverBecomes('clade'));
    }

    public function test_the_real_driver_says_plainly_when_no_key_is_set(): void
    {
        config(['services.ai.key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY is not set');

        (new ClaudeClient())->itinerary(
            ['system' => 's', 'user' => 'u', 'allowed_site_ids' => [], 'days' => 1],
            []
        );
    }
}
