<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\Category;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Tests\ApiTestCase;

/**
 * The form-driven path: ask what is on offer, ask what a filter set would give,
 * then generate from it.
 *
 * The question worth testing here is not whether a plan comes back — the
 * pipeline is proven elsewhere — but whether the three endpoints agree with
 * each other. A form that offers an interest nothing carries, or a quote that
 * promises a plan the generator then refuses, costs a traveller a credit to
 * find out.
 */
class TripFormPlanTest extends ApiTestCase
{
    private User $traveller;
    private Site $town;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->verifiedUser();
        app(CreditLedger::class)->grant($this->traveller, 10, 'test');

        // Towns are `destination` sites, and interests hang off the parents
        // named in ai_configs — the fixture has to build both halves of that
        // tree or the question set comes back empty.
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
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true, 'parent_id' => $view->id,
        ]);
        Category::create([
            'name' => 'Cave', 'code' => 'cave', 'icon' => '', 'status' => true,
            'parent_id' => $view->id,
        ]);

        $this->town = $this->site('Malvan', 'city');
    }

    private function verifiedUser(): User
    {
        $user = $this->userWithRole('user');
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function site(string $name, string $code = 'beach', float $lat = 16.06, float $lng = 73.47): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing the plan form.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ]);

        $site->categories()->attach(Category::where('code', $code)->value('id'));

        return $site;
    }

    /** Enough beaches nearby to fill `$count` stops. */
    private function beaches(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            // Spread them slightly so the distances are not all zero.
            $this->site("Beach {$i}", 'beach', 16.06 + ($i * 0.01), 73.47);
        }
    }

    private function questionSet()
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/questionSet');
    }

    private function quote(array $filters, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->traveller, 'api')
            ->postJson('/api/v2/trip/quote', $filters);
    }

    private function plan(array $filters, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->traveller, 'api')
            ->postJson('/api/v2/trip/plan', $filters);
    }

    // ── The question set ─────────────────────────────────────────────────────

    public function test_the_form_offers_only_interests_with_places_behind_them(): void
    {
        $this->beaches(4);

        $codes = collect(
            $this->assertApiSuccess($this->questionSet())->json('data.interests')
        )->pluck('code');

        $this->assertContains('beach', $codes->all());
        $this->assertNotContains(
            'cave',
            $codes->all(),
            'An interest nothing carries must not be offered: the traveller picks it, '
            . 'and the refusal arrives after they have chosen'
        );
    }

    public function test_the_form_offers_towns_it_can_search_around(): void
    {
        $response = $this->assertApiSuccess($this->questionSet());

        $this->assertSame(
            [$this->town->id],
            collect($response->json('data.towns'))->pluck('site_id')->all()
        );
        $this->assertSame(16.06, $response->json('data.towns.0.lat'));
    }

    /**
     * Devgad carries `bus_stop_type = 'Depo'` in the live data, and the
     * destination rule that keeps bus shelters out of itineraries was quietly
     * dropping the district's best-known town from the planner altogether.
     * Anchoring a trip and being worth visiting are different questions.
     */
    public function test_a_town_that_is_also_a_bus_depot_can_still_anchor_a_trip(): void
    {
        $this->beaches(8);

        $depot = $this->site('Devgad', 'city', 16.37, 73.38);
        $depot->forceFill(['bus_stop_type' => 'Depo'])->save();

        $towns = collect(
            $this->assertApiSuccess($this->questionSet())->json('data.towns')
        )->pluck('site_id')->all();

        $this->assertContains($depot->id, $towns, 'A depot town must still be offered');

        // And offering it is worthless unless it also resolves when chosen.
        $this->assertApiSuccess($this->plan([
            'town_site_ids'  => [$depot->id],
            'category_codes' => ['beach'],
            'days'           => 2,
        ]));
    }

    public function test_a_bus_shelter_is_still_never_a_destination(): void
    {
        $this->beaches(8);

        $shelter = $this->site('Some Stop', 'beach', 16.061, 73.471);
        $shelter->forceFill(['bus_stop_type' => 'Stop'])->save();

        $response = $this->assertApiSuccess($this->plan([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
        ]));

        $names = collect($response->json('data.itinerary.days'))
            ->flatMap(fn ($day) => collect($day['stops'])->pluck('site.name'))
            ->all();

        $this->assertNotContains('Some Stop', $names);
    }

    public function test_an_administrative_category_is_never_an_interest(): void
    {
        $this->beaches(3);

        $codes = collect(
            $this->assertApiSuccess($this->questionSet())->json('data.interests')
        )->pluck('code')->all();

        // `city` carries a site — the town itself — but nobody picks "City" as
        // a holiday interest. Only the configured parents are offered.
        $this->assertNotContains('city', $codes);
    }

    public function test_the_form_reports_the_live_limits_rather_than_shipping_them(): void
    {
        AiConfig::set('max_days', 5);
        AiConfig::set('max_towns', 2);

        $response = $this->assertApiSuccess($this->questionSet());

        $this->assertSame(5, $response->json('data.days.max'));
        $this->assertSame(2, $response->json('data.max_towns'));
    }

    // ── The quote ────────────────────────────────────────────────────────────

    public function test_a_quote_costs_nothing_and_calls_no_model(): void
    {
        $this->beaches(6);
        $before = app(CreditLedger::class)->balance($this->traveller);

        $this->assertApiSuccess($this->quote([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
        ]));

        $this->assertSame($before, app(CreditLedger::class)->balance($this->traveller));
        $this->assertSame(0, \App\Models\AiLog::count(), 'A quote must not reach the model');
    }

    public function test_a_quote_says_no_before_a_credit_is_spent(): void
    {
        $this->beaches(2);

        $response = $this->assertApiSuccess($this->quote([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 4,
        ]));

        $this->assertFalse($response->json('data.can_plan'));
        $this->assertSame('not_enough_places', $response->json('data.reason'));
        $this->assertSame(8, $response->json('data.needed'));
    }

    /**
     * The one agreement that matters: a quote is a promise about the plan.
     */
    public function test_a_quote_that_promises_a_plan_is_followed_by_one(): void
    {
        $this->beaches(8);

        $filters = [
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 3,
        ];

        $this->assertTrue($this->quote($filters)->json('data.can_plan'));

        $this->assertApiSuccess($this->plan($filters));
    }

    public function test_a_quote_that_refuses_is_refused_by_the_plan_too(): void
    {
        $this->beaches(2);

        $filters = [
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 5,
        ];

        $this->assertFalse($this->quote($filters)->json('data.can_plan'));

        $response = $this->assertApiFailure($this->plan($filters));
        $this->assertSame('not_enough_places', $response->json('data.reason'));
    }

    // ── Generating ───────────────────────────────────────────────────────────

    public function test_a_filter_set_becomes_an_itinerary(): void
    {
        $this->beaches(8);

        $response = $this->assertApiSuccess($this->plan([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
            'title'          => 'Malvan weekend',
        ]));

        $this->assertCount(2, $response->json('data.itinerary.days'));

        $trip = SavedTrip::find($response->json('data.trip_id'));
        $this->assertSame('generated', $trip->status);
        $this->assertSame('Malvan weekend', $trip->title);
        $this->assertSame($this->town->id, $trip->base_site_id);
        $this->assertSame(['beach'], $trip->filters['category_codes']);
    }

    public function test_a_refused_plan_leaves_no_trip_behind(): void
    {
        $this->beaches(2);

        $this->assertApiFailure($this->plan([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 6,
        ]));

        // A `draft` with nothing in it would become the open cart and hide the
        // one the traveller was actually filling.
        $this->assertSame(0, SavedTrip::count());
    }

    public function test_running_out_of_plans_leaves_no_trip_behind(): void
    {
        $this->beaches(8);

        // A verified account is granted its free plans on first use, so the
        // quota has to be zero for there to be nothing to spend.
        AiConfig::set('free_plans_on_signup', 0);

        $poor = $this->verifiedUser();
        $filters = [
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
        ];

        $response = $this->assertApiFailure($this->plan($filters, $poor));

        $this->assertSame('insufficient_credits', $response->json('data.reason'));
        $this->assertSame(0, SavedTrip::where('user_id', $poor->id)->count());
    }

    public function test_a_stay_already_booked_must_appear_in_the_plan(): void
    {
        $this->beaches(8);
        $stay = $this->site('Sagar Homestay', 'beach');

        $response = $this->assertApiSuccess($this->plan([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
            'stay'           => 'booked',
            'stay_site_id'   => $stay->id,
        ]));

        $ids = collect($response->json('data.itinerary.days'))
            ->flatMap(fn ($day) => collect($day['stops'])->pluck('site.id'))
            ->all();

        $this->assertContains($stay->id, $ids);
    }

    // ── The gates ────────────────────────────────────────────────────────────

    public function test_a_guest_cannot_generate_from_the_form(): void
    {
        $this->beaches(8);

        $guest = $this->userWithRole('user');
        $guest->forceFill(['email_verified_at' => null])->save();

        $response = $this->assertApiFailure($this->plan([
            'town_site_ids'  => [$this->town->id],
            'category_codes' => ['beach'],
            'days'           => 2,
        ], $guest->fresh()));

        $this->assertSame('verification_required', $response->json('data.reason'));
        $this->assertSame(0, SavedTrip::count());
    }

    public function test_the_form_endpoints_need_authentication(): void
    {
        $this->postJson('/api/v2/trip/questionSet')->assertStatus(401);
        $this->postJson('/api/v2/trip/quote', [])->assertStatus(401);
        $this->postJson('/api/v2/trip/plan', [])->assertStatus(401);
    }

    public function test_a_trip_must_name_a_town(): void
    {
        $this->assertApiFailure($this->plan([
            'category_codes' => ['beach'],
            'days'           => 2,
        ]));

        $this->assertSame(0, SavedTrip::count());
    }

    public function test_more_towns_than_allowed_is_refused(): void
    {
        AiConfig::set('max_towns', 1);

        $second = $this->site('Vengurla', 'city', 15.86, 73.63);

        $this->assertApiFailure($this->quote([
            'town_site_ids' => [$this->town->id, $second->id],
            'days'          => 2,
        ]));
    }
}
