<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Tests\ApiTestCase;

/**
 * The stepper's backend: browsing places, and the step-1 answers surviving
 * to the plan.
 *
 * The property worth defending hardest is parity. Browse is built on the same
 * `aroundMany` query the planner fills gaps with, so a place offered in step 2
 * can always be planned around. A list built on anything else could offer
 * something the planner then refuses, and the traveller would have no way to
 * understand why.
 */
class TripBrowseTest extends ApiTestCase
{
    private User $traveller;
    private Site $malvan;
    private Site $devgad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->verifiedUser();
        app(CreditLedger::class)->grantSignupCredits($this->traveller);
        app(CreditLedger::class)->grant($this->traveller, 20, 'test');

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
        foreach (['beach' => 'Beach', 'fort' => 'Fort'] as $code => $name) {
            Category::create([
                'name' => $name, 'code' => $code, 'icon' => '',
                'status' => true, 'parent_id' => $view->id,
            ]);
        }

        // Two towns far enough apart that one radius cannot cover both.
        $this->malvan = $this->site('Malvan', 'city', 16.06, 73.47);
        $this->devgad = $this->site('Devgad', 'city', 16.38, 73.38);

        $this->site('Tarkarli Beach', 'beach', 16.055, 73.468);
        $this->site('Rajkot Fort', 'fort', 16.061, 73.464);
        $this->site('Devgad Beach', 'beach', 16.381, 73.372);
        $this->site('Devgad Fort', 'fort', 16.379, 73.375);
    }

    private function verifiedUser(): User
    {
        $user = $this->userWithRole('user');
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function site(string $name, string $code, float $lat, float $lng): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing the stepper.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ]);

        $site->categories()->attach(Category::where('code', $code)->value('id'));

        return $site;
    }

    private function browse(array $body)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/places', $body);
    }

    private function update(array $body)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/updateCart', $body);
    }

    // ── B1 · browsing ────────────────────────────────────────────────────────

    public function test_browsing_returns_places_around_every_town_chosen(): void
    {
        $names = collect(
            $this->assertApiSuccess($this->browse([
                'town_site_ids'  => [$this->malvan->id, $this->devgad->id],
                'category_codes' => ['beach', 'fort'],
            ]))->json('data.places')
        )->pluck('name');

        // One radius around either town would miss the other's places.
        $this->assertContains('Tarkarli Beach', $names->all());
        $this->assertContains('Devgad Beach', $names->all());
    }

    public function test_browsing_honours_the_interest_filter(): void
    {
        $names = collect(
            $this->assertApiSuccess($this->browse([
                'town_site_ids'  => [$this->malvan->id],
                'category_codes' => ['fort'],
            ]))->json('data.places')
        )->pluck('name')->all();

        $this->assertContains('Rajkot Fort', $names);
        $this->assertNotContains('Tarkarli Beach', $names);
    }

    public function test_browsing_can_be_narrowed_by_name(): void
    {
        $names = collect(
            $this->assertApiSuccess($this->browse([
                'town_site_ids'  => [$this->malvan->id, $this->devgad->id],
                'category_codes' => ['beach', 'fort'],
                'search'         => 'devgad',
            ]))->json('data.places')
        )->pluck('name')->all();

        $this->assertContains('Devgad Beach', $names);
        $this->assertNotContains('Tarkarli Beach', $names);
    }

    public function test_browsing_states_the_minimum_so_the_step_can_show_it(): void
    {
        $data = $this->assertApiSuccess($this->browse([
            'town_site_ids' => [$this->malvan->id],
        ]))->json('data');

        // Step 2's progress counter reads this rather than hardcoding 3.
        $this->assertSame(3, $data['minimum']);
    }

    public function test_browsing_needs_a_town(): void
    {
        $this->assertApiFailure($this->browse(['category_codes' => ['beach']]));
    }

    public function test_browsing_a_place_a_bus_shelter_is_never_offered(): void
    {
        $shelter = $this->site('Some Stop', 'beach', 16.057, 73.469);
        $shelter->forceFill(['bus_stop_type' => 'Stop'])->save();

        $names = collect(
            $this->assertApiSuccess($this->browse([
                'town_site_ids'  => [$this->malvan->id],
                'category_codes' => ['beach'],
            ]))->json('data.places')
        )->pluck('name')->all();

        $this->assertNotContains('Some Stop', $names);
    }

    public function test_browsing_costs_nothing(): void
    {
        $before = app(CreditLedger::class)->balance($this->traveller);

        $this->assertApiSuccess($this->browse([
            'town_site_ids' => [$this->malvan->id],
        ]));

        $this->assertSame($before, app(CreditLedger::class)->balance($this->traveller));
        $this->assertSame(0, \App\Models\AiLog::count());
    }

    public function test_browsing_needs_authentication(): void
    {
        $this->postJson('/api/v2/trip/places', ['town_site_ids' => [1]])->assertStatus(401);
    }

    // ── B2 · the step-1 answers surviving ────────────────────────────────────

    private function cartWith(array $names): SavedTrip
    {
        $trip = SavedTrip::create([
            'user_id' => $this->traveller->id,
            'status'  => 'draft',
            'days'    => 2,
        ]);

        foreach ($names as $i => $name) {
            Favourite::create([
                'user_id'           => $this->traveller->id,
                'trip_id'           => $trip->id,
                'sort_order'        => $i,
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => Site::where('name', $name)->value('id'),
            ]);
        }

        return $trip->fresh();
    }

    public function test_saving_step_two_does_not_wipe_step_one(): void
    {
        $trip = $this->cartWith(['Tarkarli Beach']);

        $this->assertApiSuccess($this->update([
            'trip_id' => $trip->id,
            'filters' => [
                'town_site_ids'  => [$this->malvan->id],
                'category_codes' => ['beach'],
            ],
        ]));

        // A later step saves its own answer. A replace would lose the towns.
        $this->assertApiSuccess($this->update([
            'trip_id' => $trip->id,
            'filters' => ['arrival' => ['mode' => 'train', 'at' => '13:40']],
        ]));

        $filters = $trip->fresh()->filters;

        $this->assertSame([$this->malvan->id], $filters['town_site_ids']);
        $this->assertSame(['beach'], $filters['category_codes']);
        $this->assertSame('train', $filters['arrival']['mode']);
    }

    public function test_a_cart_plan_fills_around_every_town_the_stepper_chose(): void
    {
        $trip = $this->cartWith(['Tarkarli Beach', 'Rajkot Fort', 'Devgad Fort']);

        $this->assertApiSuccess($this->update([
            'trip_id' => $trip->id,
            'filters' => [
                'town_site_ids'  => [$this->malvan->id, $this->devgad->id],
                'category_codes' => ['beach', 'fort'],
            ],
        ]));

        $response = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')
                ->postJson('/api/v2/trip/planFromCart', ['trip_id' => $trip->id])
        );

        $names = collect($response->json('data.itinerary.days'))
            ->flatMap(fn ($day) => collect($day['stops'])->pluck('site.name'))
            ->all();

        // Before this, planFromCart anchored on ONE site and ignored
        // filters.town_site_ids, so a three-town trip was filled from one town.
        $this->assertContains('Devgad Beach', $names);
    }

    public function test_an_arrival_saved_by_the_stepper_reaches_the_plan(): void
    {
        $trip = $this->cartWith(['Tarkarli Beach', 'Rajkot Fort', 'Devgad Fort']);

        $this->assertApiSuccess($this->update([
            'trip_id' => $trip->id,
            'filters' => [
                'town_site_ids' => [$this->malvan->id],
                'arrival'       => ['mode' => 'own_vehicle', 'at' => '13:40'],
            ],
        ]));

        $arrival = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')
                ->postJson('/api/v2/trip/planFromCart', ['trip_id' => $trip->id])
        )->json('data.itinerary.arrival');

        // Stored and then ignored before: arrival was resolved on the form
        // path only, so a stepper trip carried a time that never landed.
        $this->assertSame('own_vehicle', $arrival['mode']);
        $this->assertSame('14:40', $arrival['earliest_start']);
    }

    public function test_filters_cannot_be_given_a_shape_the_planner_cannot_read(): void
    {
        $trip = $this->cartWith(['Tarkarli Beach']);

        $this->assertApiFailure($this->update([
            'trip_id' => $trip->id,
            'filters' => ['arrival' => ['mode' => 'teleport']],
        ]));

        $this->assertApiFailure($this->update([
            'trip_id' => $trip->id,
            'filters' => ['radius_km' => 9999],
        ]));
    }
}
