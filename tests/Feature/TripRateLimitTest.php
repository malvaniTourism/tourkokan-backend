<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\Category;
use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Illuminate\Support\Facades\RateLimiter;
use Tests\ApiTestCase;

/**
 * The throttle on trip generation.
 *
 * It exists to cap SPENDING, which is why what it counts matters as much as
 * what it blocks. A refusal that never reaches the model costs nothing, and
 * counting it meant a traveller who mistyped their cart five times had burned
 * a quarter of the day's allowance on calls that were never made.
 *
 * The limits come from `ai_configs` so an admin can tighten them during a
 * spike without a deploy; these set them low and then walk into them.
 */
class TripRateLimitTest extends ApiTestCase
{
    private User $traveller;
    private Category $beach;
    private Site $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->userWithRole('user');
        $this->traveller->forceFill(['isVerified' => 1])->save();
        app(CreditLedger::class)->grant($this->traveller, 50, 'test');

        $this->beach = Category::create([
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true,
        ]);

        $this->base = $this->site('Malvan', 16.0590, 73.4680);

        RateLimiter::clear('trip:h:' . $this->traveller->id);
        RateLimiter::clear('trip:d:' . $this->traveller->id);
    }

    private function site(string $name, float $lat, float $lng): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing the throttle.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ]);

        $site->categories()->attach($this->beach->id);

        return $site;
    }

    /** A trip with `$n` places in its cart. */
    private function trip(int $n): SavedTrip
    {
        $trip = SavedTrip::create([
            'user_id'      => $this->traveller->id,
            'status'       => 'draft',
            'days'         => 2,
            'base_site_id' => $this->base->id,
            'filters'      => ['category_codes' => ['beach'], 'radius_km' => 40],
        ]);

        for ($i = 0; $i < $n; $i++) {
            Favourite::create([
                'user_id'           => $this->traveller->id,
                'trip_id'           => $trip->id,
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => $this->site("Place {$i}", 16.04 + ($i / 500), 73.47)->id,
            ]);
        }

        // Spares for the planner to fill days from.
        $this->site('Spare one', 16.07, 73.47);
        $this->site('Spare two', 16.08, 73.47);

        return $trip->fresh();
    }

    private function plan(SavedTrip $trip)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/planFromCart', ['trip_id' => $trip->id]);
    }

    public function test_a_refusal_that_never_reaches_the_model_does_not_count(): void
    {
        AiConfig::set('rate_limit_per_day', 3);

        // Two places is below min_cart_places, so this is refused before any
        // call is made and costs nothing.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->plan($this->trip(2));
            $this->assertSame(
                200,
                $response->status(),
                'A free refusal must not be throttled: ' . $response->getContent()
            );
        }

        // The allowance is untouched, so a real plan still goes through.
        $this->assertApiSuccess($this->plan($this->trip(4)));
    }

    public function test_the_limit_still_blocks_once_real_plans_have_been_made(): void
    {
        AiConfig::set('rate_limit_per_day', 2);

        $this->assertApiSuccess($this->plan($this->trip(4)));
        $this->assertApiSuccess($this->plan($this->trip(4)));

        $this->plan($this->trip(4))->assertStatus(429);
    }

    public function test_the_refusal_says_which_limit_it_was_and_in_our_own_shape(): void
    {
        AiConfig::set('rate_limit_per_day', 1);

        $this->assertApiSuccess($this->plan($this->trip(4)));

        $response = $this->plan($this->trip(4))->assertStatus(429);

        // Laravel's own 429 body is its own shape, which the app read as an
        // unknown failure. And the screen rendered Retry-After as minutes, so
        // a DAILY limit said "give it a minute" and printed 1440 of them.
        $this->assertFalse($response->json('success'));
        $this->assertSame('rate_limited', $response->json('data.reason'));
        $this->assertSame('day', $response->json('data.scope'));
        $this->assertGreaterThan(0, $response->json('data.retry_after'));
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_an_hourly_refusal_names_the_hour(): void
    {
        AiConfig::set('rate_limit_per_hour', 1);
        AiConfig::set('rate_limit_per_day', 50);

        $this->assertApiSuccess($this->plan($this->trip(4)));

        $this->assertSame(
            'hour',
            $this->plan($this->trip(4))->assertStatus(429)->json('data.scope')
        );
    }

    public function test_a_zero_in_the_config_does_not_lock_everyone_out(): void
    {
        // The admin screen validates min:1, so this is only reachable by
        // editing the row directly — which is exactly when a typo must not
        // become an outage for every traveller.
        AiConfig::set('rate_limit_per_day', 0);

        $this->assertApiSuccess($this->plan($this->trip(4)));
    }
}
