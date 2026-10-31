<?php

namespace Tests\Feature;

use App\Models\AiLog;
use App\Models\Category;
use App\Models\Favourite;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\Ai\AiClient;
use App\Services\Trip\CandidateFetcher;
use App\Services\Trip\TripPlanner;
use Tests\ApiTestCase;

/**
 * The trip-planner pipeline, end to end, with no API key and no spend.
 *
 * `AI_DRIVER=fake` is the default, so everything here runs in CI: candidates
 * are fetched from real rows, legs are resolved from real route_stops, and the
 * assembler's guarantees are exercised against a stub that misbehaves on
 * purpose.
 */
class TripPlannerPipelineTest extends ApiTestCase
{
    private User $traveller;
    private Category $beach;
    private Site $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->userWithRole('user');

        // Generation costs a credit now. These tests are about the pipeline,
        // not the quota — TripCreditsTest covers running out.
        app(CreditLedger::class)->grant($this->traveller, 10, 'test');

        $this->beach = Category::create([
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true,
        ]);

        $this->base = $this->site('Devgad Town', 16.3800, 73.3800);
    }

    private function site(
        string $name,
        float $lat,
        float $lng,
        ?string $busStopType = null,
        bool $approved = true
    ): Site {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing the planner.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => $approved ? 'approved' : 'pending',
            'latitude'          => $lat,
            'longitude'         => $lng,
            'bus_stop_type'     => $busStopType,
        ]);

        $site->categories()->attach($this->beach->id);

        return $site;
    }

    /** A draft trip holding the given sites as its cart. */
    private function cart(array $sites, int $days = 2): SavedTrip
    {
        $trip = SavedTrip::create([
            'user_id'      => $this->traveller->id,
            'status'       => 'draft',
            'days'         => $days,
            'base_site_id' => $this->base->id,
            'filters'      => ['category_codes' => ['beach'], 'radius_km' => 40],
        ]);

        foreach ($sites as $site) {
            Favourite::create([
                'user_id'           => $this->traveller->id,
                'trip_id'           => $trip->id,
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => $site->id,
            ]);
        }

        return $trip->fresh();
    }

    /** Four picked places plus two more nearby for the planner to suggest. */
    private function sixPlaces(): array
    {
        $picked = [
            $this->site('Kunkeshwar Beach', 16.3900, 73.3900),
            $this->site('Vijaydurg Fort', 16.4000, 73.4000),
            $this->site('Aare Ware Beach', 16.3700, 73.3700),
            $this->site('Mithbav Jetty', 16.3600, 73.3600),
        ];

        $this->site('Taramumbri Beach', 16.3500, 73.3500);
        $this->site('Kokan Katta', 16.3850, 73.3850);

        return $picked;
    }

    // ── The headline: a cart becomes a real itinerary, free ──────────────────

    public function test_a_cart_becomes_a_day_by_day_itinerary(): void
    {
        $trip = $this->cart($this->sixPlaces());

        $result = app(TripPlanner::class)->planFromCart($trip);

        $this->assertTrue($result['ok'], 'Pipeline failed: ' . ($result['reason'] ?? ''));

        $itinerary = $result['itinerary'];
        $this->assertSame(2, $itinerary['trip']['days']);
        $this->assertCount(2, $itinerary['days']);

        foreach ($itinerary['days'] as $day) {
            $this->assertGreaterThanOrEqual(2, count($day['stops']));

            foreach ($day['stops'] as $stop) {
                // Every fact comes from our own row, never from the model.
                $this->assertArrayHasKey('name', $stop['site']);
                $this->assertArrayHasKey('phone', $stop['site']);
                $this->assertIsInt($stop['site']['id']);
            }
        }
    }

    public function test_every_place_the_traveller_picked_appears_in_the_plan(): void
    {
        $picked = $this->sixPlaces();
        $result = app(TripPlanner::class)->planFromCart($this->cart($picked));

        $this->assertTrue($result['ok']);

        $planned = collect($result['itinerary']['days'])
            ->flatMap(fn ($d) => array_column(array_column($d['stops'], 'site'), 'id'))
            ->all();

        foreach ($picked as $site) {
            $this->assertContains($site->id, $planned, "{$site->name} was dropped from the plan");
        }
    }

    // ── The trap: most "sites" are bus shelters ──────────────────────────────

    public function test_bus_stops_are_never_offered_as_destinations(): void
    {
        $this->sixPlaces();
        $shelter = $this->site('Devgad ST Stand', 16.3810, 73.3810, busStopType: 'depot');

        $candidates = app(CandidateFetcher::class)->nearby(
            (float) $this->base->latitude,
            (float) $this->base->longitude,
            ['beach'],
        );

        $this->assertNotContains(
            $shelter->id,
            $candidates->pluck('id')->all(),
            'A bus stop reached the candidate set — bus_stop_type is not being excluded'
        );
    }

    public function test_an_unapproved_site_is_never_offered(): void
    {
        $this->sixPlaces();
        $pending = $this->site('Unapproved Shack', 16.3820, 73.3820, approved: false);

        $candidates = app(CandidateFetcher::class)->nearby(
            (float) $this->base->latitude,
            (float) $this->base->longitude,
            ['beach'],
        );

        $this->assertNotContains($pending->id, $candidates->pluck('id')->all());
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    public function test_a_hallucinated_site_id_never_reaches_the_traveller(): void
    {
        $picked = $this->sixPlaces();
        $trip   = $this->cart($picked);

        $strangerId = 999999;

        // A model that invents a place. The fake driver never does, so the
        // misbehaviour is injected rather than hoped for.
        $this->app->bind(AiClient::class, fn () => new class ($picked, $strangerId) implements AiClient {
            public function __construct(private array $picked, private int $stranger)
            {
            }

            public function itinerary(array $prompt, array $schema): array
            {
                $ids = array_map(fn ($s) => (int) $s->id, $this->picked);

                return [
                    'data' => [
                        'trip' => ['title' => 'Trip', 'summary' => ''],
                        'days' => [
                            ['day' => 1, 'theme' => 'One', 'stops' => [
                                ['site_id' => $ids[0], 'arrive' => '09:00', 'depart' => '11:00', 'note' => 'a'],
                                ['site_id' => $this->stranger, 'arrive' => '12:00', 'depart' => '13:00', 'note' => 'invented'],
                                ['site_id' => $ids[1], 'arrive' => '14:00', 'depart' => '16:00', 'note' => 'b'],
                            ]],
                            ['day' => 2, 'theme' => 'Two', 'stops' => [
                                ['site_id' => $ids[2], 'arrive' => '09:00', 'depart' => '11:00', 'note' => 'c'],
                                ['site_id' => $ids[3], 'arrive' => '12:00', 'depart' => '14:00', 'note' => 'd'],
                            ]],
                        ],
                        'warnings' => [],
                    ],
                    'meta' => ['driver' => 'stub', 'model' => 'stub'],
                ];
            }
        });

        $result = app(TripPlanner::class)->planFromCart($trip);

        $this->assertTrue($result['ok'], 'The plan should survive one dropped stranger');

        $planned = collect($result['itinerary']['days'])
            ->flatMap(fn ($d) => array_column(array_column($d['stops'], 'site'), 'id'))
            ->all();

        $this->assertNotContains($strangerId, $planned);

        // And the drop is recorded, so hallucination is a metric not a ticket.
        $log = AiLog::latest('id')->first();
        $this->assertContains($strangerId, $log->dropped_site_ids);
    }

    public function test_a_day_left_too_thin_fails_rather_than_being_served(): void
    {
        $picked = $this->sixPlaces();
        $trip   = $this->cart($picked);

        $this->app->bind(AiClient::class, fn () => new class ($picked) implements AiClient {
            public function __construct(private array $picked)
            {
            }

            public function itinerary(array $prompt, array $schema): array
            {
                return [
                    'data' => [
                        'trip' => ['title' => 'Trip', 'summary' => ''],
                        'days' => [
                            ['day' => 1, 'theme' => 'One', 'stops' => [
                                ['site_id' => (int) $this->picked[0]->id, 'arrive' => '09:00', 'depart' => '11:00', 'note' => 'only one'],
                            ]],
                        ],
                        'warnings' => [],
                    ],
                    'meta' => ['driver' => 'stub'],
                ];
            }
        });

        $result = app(TripPlanner::class)->planFromCart($trip);

        $this->assertFalse($result['ok']);
        $this->assertSame('generation_failed', $result['reason']);
        $this->assertSame('day_too_thin', AiLog::latest('id')->first()->failure_reason);
    }

    public function test_a_cart_below_the_minimum_is_refused_without_calling_the_model(): void
    {
        $trip = $this->cart([
            $this->site('Lonely Beach', 16.3900, 73.3900),
        ]);

        $result = app(TripPlanner::class)->planFromCart($trip);

        $this->assertFalse($result['ok']);
        $this->assertSame('cart_too_small', $result['reason']);
        $this->assertSame(0, AiLog::count(), 'A refused cart must not spend a model call');
    }

    // ── Travel comes from our own data ───────────────────────────────────────

    public function test_bus_legs_are_resolved_from_route_stops(): void
    {
        $picked = $this->sixPlaces();

        $route = Route::create([
            'route_no'     => 142,
            'name'         => 'Devgad — Kunkeshwar',
            'start_time'   => '09:05:00',
            'end_time'     => '09:40:00',
            'working_days' => 'daily',
            'status'       => true,
        ]);

        RouteStops::create([
            'route_no' => 142, 'serial_no' => 1, 'route_id' => $route->id,
            'site_id' => $picked[0]->id, 'arr_time' => '09:00:00', 'dept_time' => '09:05:00',
        ]);
        RouteStops::create([
            'route_no' => 142, 'serial_no' => 2, 'route_id' => $route->id,
            'site_id' => $picked[1]->id, 'arr_time' => '09:40:00', 'dept_time' => '09:45:00',
        ]);

        $legs = app(\App\Services\Trip\LegResolver::class)
            ->busOptions($picked[0]->id, $picked[1]->id);

        $this->assertNotEmpty($legs, 'A direct route exists but was not found');
        $this->assertSame(142, (int) $legs[0]['route_no']);
        $this->assertSame('09:05', $legs[0]['depart']);
        $this->assertSame('09:40', $legs[0]['arrive']);
    }

    public function test_the_leg_is_one_directional(): void
    {
        $picked = $this->sixPlaces();

        $route = Route::create([
            'route_no' => 142, 'name' => 'One way', 'start_time' => '09:05:00',
            'end_time' => '09:40:00', 'status' => true,
        ]);
        RouteStops::create([
            'route_no' => 142, 'serial_no' => 1, 'route_id' => $route->id,
            'site_id' => $picked[0]->id, 'arr_time' => '09:00:00', 'dept_time' => '09:05:00',
        ]);
        RouteStops::create([
            'route_no' => 142, 'serial_no' => 2, 'route_id' => $route->id,
            'site_id' => $picked[1]->id, 'arr_time' => '09:40:00', 'dept_time' => '09:45:00',
        ]);

        $resolver = app(\App\Services\Trip\LegResolver::class);

        $this->assertNotEmpty($resolver->busOptions($picked[0]->id, $picked[1]->id));
        $this->assertEmpty(
            $resolver->busOptions($picked[1]->id, $picked[0]->id),
            'The return direction is a different service and must not be invented'
        );
    }

    // ── The audit trail ──────────────────────────────────────────────────────

    public function test_every_generation_is_logged(): void
    {
        $trip = $this->cart($this->sixPlaces());

        app(TripPlanner::class)->planFromCart($trip);

        $log = AiLog::latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('cart', $log->type);
        $this->assertSame('success', $log->status);
        $this->assertSame($trip->id, $log->trip_id);
        $this->assertSame('fake', $log->driver);
        $this->assertGreaterThan(0, $log->candidates_sent);
        $this->assertNotEmpty($log->prompt_hash);
    }

    public function test_no_money_is_spent_on_the_fake_driver(): void
    {
        app(TripPlanner::class)->planFromCart($this->cart($this->sixPlaces()));

        $this->assertSame(0.0, AiLog::spentToday());
    }
}
