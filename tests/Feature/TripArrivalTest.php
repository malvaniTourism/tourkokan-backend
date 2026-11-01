<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Tests\ApiTestCase;

/**
 * How the traveller gets to Kokan, and what that does to the plan.
 *
 * The point of asking is not to decorate the itinerary with a train time. It
 * is that a plan which has someone at a beach at 09:00 when their train gets
 * in at 13:40 — and the only onward bus goes at 17:20 — is wrong, and wrong
 * in a way that wastes their first day.
 *
 * Two rules are worth holding down hardest: the first day starts when they
 * are actually in town, not when they land; and it is exempt from the usual
 * minimum, because an evening arrival legitimately leaves room for one stop.
 */
class TripArrivalTest extends ApiTestCase
{
    private User $traveller;
    private Site $town;
    private Site $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->verifiedUser();
        app(CreditLedger::class)->grantSignupCredits($this->traveller);
        app(CreditLedger::class)->grant($this->traveller, 30, 'test');

        $destination = Category::create([
            'name' => 'Destination', 'code' => 'destination', 'icon' => '', 'status' => true,
        ]);
        Category::create([
            'name' => 'City', 'code' => 'city', 'icon' => '', 'status' => true,
            'parent_id' => $destination->id,
        ]);
        $view = Category::create([
            'name' => 'Kokan View', 'code' => 'kokan_view', 'icon' => '', 'status' => true,
        ]);
        Category::create([
            'name' => 'Beach', 'code' => 'beach', 'icon' => '', 'status' => true,
            'parent_id' => $view->id,
        ]);

        $transport = Category::create([
            'name' => 'Transportation', 'code' => 'transportation', 'icon' => '', 'status' => true,
        ]);
        Category::create([
            'name' => 'Railway Station', 'code' => 'railway_station', 'icon' => '',
            'status' => true, 'parent_id' => $transport->id,
        ]);

        $this->town = $this->site('Kankavli', 'city');
        $this->beaches(8);

        // A station is a bus stop — that is the whole reason it must not be
        // fetched through the destination rule.
        $this->station = $this->site('Kankavli Railway Station', 'railway_station');
        $this->station->forceFill(['bus_stop_type' => 'Stop'])->save();
    }

    private function verifiedUser(): User
    {
        $user = $this->userWithRole('user');
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function site(string $name, string $code, float $lat = 16.26, float $lng = 73.70): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing arrivals.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ]);

        $site->categories()->attach(Category::where('code', $code)->value('id'));

        return $site;
    }

    private function beaches(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->site("Beach {$i}", 'beach', 16.26 + ($i * 0.005), 73.70);
        }
    }

    /** A bus from the station to the town, departing at a given time. */
    private function bus(string $departs, string $arrives): Route
    {
        $route = Route::create([
            'route_no' => '126011', 'name' => 'Station to town', 'status' => false,
        ]);

        RouteStops::create([
            'route_id' => $route->id, 'site_id' => $this->station->id,
            'serial_no' => 1, 'dept_time' => $departs, 'arr_time' => $departs,
        ]);
        RouteStops::create([
            'route_id' => $route->id, 'site_id' => $this->town->id,
            'serial_no' => 2, 'dept_time' => $arrives, 'arr_time' => $arrives,
        ]);

        return $route;
    }

    private function quote(array $filters)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/quote', $filters);
    }

    private function plan(array $filters)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/plan', $filters);
    }

    private function base(array $extra = []): array
    {
        return array_merge([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
        ], $extra);
    }

    // ── What the form is offered ─────────────────────────────────────────────

    public function test_the_form_offers_the_ways_into_the_district(): void
    {
        // A station only counts once something runs from it — see the next
        // test, which is the other half of this rule.
        $this->bus('17:20', '17:24');

        $data = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/questionSet')
        )->json('data');

        $points = collect($data['arrival']['points']);

        $this->assertSame([$this->station->id], $points->pluck('site_id')->all());
        $this->assertSame(['train'], $points->first()['modes']);
        $this->assertContains('own_vehicle', collect($data['arrival']['modes'])->pluck('code')->all());
    }

    public function test_an_arrival_point_nothing_runs_to_is_not_offered(): void
    {
        // No route touches it, so it is not somewhere anyone can arrive.
        $orphan = $this->site('Ghost Halt', 'railway_station');
        $orphan->forceFill(['bus_stop_type' => 'Stop'])->save();

        $points = collect(
            $this->actingAs($this->traveller, 'api')
                ->postJson('/api/v2/trip/questionSet')->json('data.arrival.points')
        )->pluck('site_id')->all();

        $this->assertNotContains($orphan->id, $points);
    }

    public function test_budget_is_advertised_as_not_ready(): void
    {
        $data = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/questionSet')
        )->json('data');

        // Prices exist for almost nothing, so the app must not offer a filter
        // that would silently match everything or nothing.
        $this->assertFalse($data['budget']['available']);
    }

    // ── The quote answers the question you cannot answer yourself ────────────

    public function test_the_quote_names_the_onward_bus_after_the_train_gets_in(): void
    {
        $this->bus('06:10', '06:40');   // long gone by the time they land
        $this->bus('17:20', '17:24');

        $arrival = $this->assertApiSuccess($this->quote($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '13:40'],
        ])))->json('data.arrival');

        $this->assertSame('17:20', $arrival['onward']['depart']);
        $this->assertSame([$this->town->id], $arrival['reaches']);
    }

    public function test_the_quote_says_when_no_bus_runs_from_where_they_land(): void
    {
        // Station exists, nothing connects it to the chosen town.
        $arrival = $this->assertApiSuccess($this->quote($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '13:40'],
        ])))->json('data.arrival');

        $this->assertSame([], $arrival['reaches']);
        $this->assertNull($arrival['onward']);
    }

    public function test_the_day_cannot_start_before_the_onward_bus_arrives(): void
    {
        $this->bus('17:20', '17:24');

        $arrival = $this->assertApiSuccess($this->quote($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '13:40'],
        ])))->json('data.arrival');

        // Landing at 13:40 suggests 14:40, but they are on a platform until
        // 17:24 — promising a 14:40 stop would strand them watching the bus.
        $this->assertSame('17:54', $arrival['earliest_start']);
    }

    // ── What it does to the plan ─────────────────────────────────────────────

    public function test_an_evening_arrival_shortens_the_first_day(): void
    {
        $this->bus('17:20', '17:24');

        $days = $this->assertApiSuccess($this->plan($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '13:40'],
        ])))->json('data.itinerary.days');

        $this->assertGreaterThanOrEqual(
            '17:54',
            $days[0]['stops'][0]['arrive'],
            'Day one must not begin before the traveller is in town'
        );
        $this->assertLessThan(
            count($days[1]['stops']),
            count($days[0]['stops']),
            'An evening arrival leaves less of day one than a full day'
        );
    }

    public function test_an_early_arrival_leaves_the_first_day_whole(): void
    {
        $this->bus('06:10', '06:40');

        $days = $this->assertApiSuccess($this->plan($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '05:30'],
        ])))->json('data.itinerary.days');

        $this->assertSame(count($days[1]['stops']), count($days[0]['stops']));
    }

    public function test_arriving_by_car_needs_no_station(): void
    {
        $response = $this->assertApiSuccess($this->plan($this->base([
            'arrival' => ['mode' => 'own_vehicle'],
        ])));

        $this->assertSame('own_vehicle', $response->json('data.itinerary.arrival.mode'));
    }

    public function test_a_train_arrival_must_say_where(): void
    {
        $this->assertApiFailure($this->quote($this->base([
            'arrival' => ['mode' => 'train', 'at' => '13:40'],
        ])));
    }

    public function test_saying_nothing_about_arrival_still_works(): void
    {
        $response = $this->assertApiSuccess($this->plan($this->base()));

        $this->assertArrayNotHasKey('arrival', $response->json('data.itinerary'));
    }

    public function test_the_finished_plan_carries_the_journey_in(): void
    {
        $this->bus('17:20', '17:24');

        $arrival = $this->assertApiSuccess($this->plan($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '13:40'],
        ])))->json('data.itinerary.arrival');

        $this->assertSame('Kankavli Railway Station', $arrival['from']);
        $this->assertSame('13:40', $arrival['at']);
        $this->assertSame('17:20', $arrival['onward']['depart']);
    }

    // ── The cache must not mix arrivals up ───────────────────────────────────

    public function test_two_travellers_arriving_at_different_times_do_not_share_a_plan(): void
    {
        // Two services, so the early traveller is not also stuck waiting for
        // the evening bus — which is what makes the two days differ at all.
        $this->bus('06:10', '06:40');
        $this->bus('17:20', '17:24');

        $morning = $this->assertApiSuccess($this->plan($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '06:00'],
        ])))->json('data.itinerary.days');

        $evening = $this->assertApiSuccess($this->plan($this->base([
            'arrival' => ['mode' => 'train', 'site_id' => $this->station->id, 'at' => '13:40'],
        ])))->json('data.itinerary.days');

        // Same places, same days, same town — only the hour differs, and the
        // cache key has to notice. It is keyed on the hour, so 06:00 and
        // 13:40 are separate entries.
        $this->assertNotSame(
            $morning[0]['stops'][0]['arrive'],
            $evening[0]['stops'][0]['arrive'],
            'An evening arrival must not be served the morning traveller’s day one'
        );
    }
}
