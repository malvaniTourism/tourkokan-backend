<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\TripPlacement;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\TripPlanner;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;

/**
 * A place the traveller found themselves.
 *
 * The whole feature rests on one decision: a pasted pin is kept on the trip's
 * `custom_stops`, never written to `sites`. It has no owner, no approval and no
 * phone number, and a row in `sites` would leak it into everyone else's search
 * results. The planner still has to be able to speak about it, so it travels as
 * an unsaved Site model with a NEGATIVE id — an id space the table can never
 * produce, which makes "ours or theirs" a sign test rather than a lookup.
 *
 * These cover both halves: the link that becomes a point, and the point that
 * becomes a stop in a real itinerary. Everything runs on `AI_DRIVER=fake` and
 * `Http::fake`, so there is no network and no spend.
 */
class TripCustomStopTest extends ApiTestCase
{
    private User $traveller;
    private Category $beach;
    private Site $town;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->userWithRole('user');
        app(CreditLedger::class)->grant($this->traveller, 10, 'test');

        // The resolver HEADs the link to follow redirects. Without this every
        // test here reaches google.com — slow, and it fails outright on a
        // machine with no network, where expand() returns null and a perfectly
        // good link reads as "unreachable". A plain ?q= link needs no redirect,
        // so an empty 200 leaves the URL as it was. Tests that depend on a
        // redirect fake it themselves.
        Http::fake(['*' => Http::response('', 200)]);

        // Without this an unmatched URL is sent for real. That is how the
        // short-link test below first "passed": its pattern missed, Laravel
        // let the request out, and google.com answered it.
        Http::preventStrayRequests();

        $this->beach = Category::create([
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true,
        ]);

        // A town is what the resolver measures "inside the district" against,
        // and it is recognised by its category, not its name.
        $city = Category::create([
            'name' => 'City', 'mr_name' => 'शहर', 'code' => 'city',
            'icon' => '', 'status' => true,
        ]);

        $this->town = $this->site('Malvan', 16.0590, 73.4680);
        $this->town->categories()->attach($city->id);
    }

    private function site(string $name, float $lat, float $lng): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing pasted pins.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
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
            'base_site_id' => $this->town->id,
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

    /** Four nearby places to pick, plus two spares for the planner to offer. */
    private function sixPlaces(): array
    {
        $picked = [
            $this->site('Tarkarli Beach', 16.0400, 73.4700),
            $this->site('Sindhudurg Fort', 16.0450, 73.4600),
            $this->site('Devbag Sangam', 16.0300, 73.4750),
            $this->site('Rock Garden', 16.0620, 73.4650),
        ];

        $this->site('Chivla Beach', 16.0550, 73.4620);
        $this->site('Tondavali Beach', 16.0900, 73.4800);

        return $picked;
    }

    private function resolve(string $url)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/resolveMapLink', ['url' => $url]);
    }

    private function add(array $body)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/addCustomStop', $body);
    }

    private function remove(int $tripId, string $key)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/removeCustomStop', ['trip_id' => $tripId, 'key' => $key]);
    }

    private function cartOf(int $tripId)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/cart', ['trip_id' => $tripId]);
    }

    /** Every stop in the plan, flattened. */
    private function stops(array $itinerary): array
    {
        return collect($itinerary['days'])->flatMap(fn ($d) => $d['stops'])->all();
    }

    // ── The link becomes a point ─────────────────────────────────────────────

    public function test_a_pasted_link_inside_the_district_is_accepted(): void
    {
        // A plain ?q= link carries its coordinates, so nothing is fetched.
        $response = $this->assertApiSuccess(
            $this->resolve('https://www.google.com/maps?q=16.0410,73.4690')
        );

        $this->assertTrue($response->json('data.inside'));
        $this->assertSame('Malvan', $response->json('data.nearest_town'));
        $this->assertSame(16.041, $response->json('data.latitude'));
        $this->assertSame(73.469, $response->json('data.longitude'));
        // Nothing to offer: they are already somewhere we cover.
        $this->assertSame([], $response->json('data.nearby'));
    }

    public function test_a_point_we_do_not_cover_is_refused_with_somewhere_we_do(): void
    {
        // Pune — real, and nowhere near a town this district knows.
        $response = $this->assertApiSuccess(
            $this->resolve('https://www.google.com/maps?q=18.5204,73.8567')
        );

        $this->assertFalse(
            $response->json('data.inside'),
            'A point 250km away must not be planned around'
        );
        // The refusal has to offer a choice, or it is just a dead end.
        $this->assertNotEmpty($response->json('data.nearby'));
    }

    public function test_a_link_with_no_coordinates_in_it_is_refused(): void
    {
        $response = $this->resolve('https://www.google.com/maps/search/beaches');

        $this->assertFalse($response->json('success'));
        $this->assertSame('no_coordinates', $response->json('data.reason'));
    }

    /**
     * Google has used several URL shapes and has changed them before, so the
     * resolver tries each in turn. This is the part that is ours.
     *
     * The short-link case (maps.app.goo.gl, which carries no coordinates at
     * all) is deliberately NOT covered here: expand() reads Guzzle's
     * X-Guzzle-Redirect-History, which the HTTP fake does not populate, so a
     * test for it would only assert that the fake behaves like the fake. It
     * was verified against the real service on device instead.
     */
    public function test_every_maps_url_shape_we_accept_yields_the_same_point(): void
    {
        $shapes = [
            'place marker'   => 'https://www.google.com/maps/place/Nivti/data=!3d16.0412!4d73.4691',
            'map centre'     => 'https://www.google.com/maps/place/Nivti/@16.0412,73.4691,17z',
            'query'          => 'https://www.google.com/maps?q=16.0412,73.4691',
            'query encoded'  => 'https://www.google.com/maps?q=16.0412,%2073.4691',
            'latlng param'   => 'https://maps.google.com/?ll=16.0412,73.4691&z=16',
            'directions'     => 'https://www.google.com/maps/dir/?api=1&destination=16.0412,73.4691',
        ];

        foreach ($shapes as $shape => $url) {
            $response = $this->resolve($url);

            $this->assertTrue(
                $response->json('success'),
                "The {$shape} shape was not read: " . $response->getContent()
            );
            $this->assertSame(16.0412, $response->json('data.latitude'), "{$shape} latitude");
            $this->assertSame(73.4691, $response->json('data.longitude'), "{$shape} longitude");
            $this->assertTrue($response->json('data.inside'), "{$shape} should be inside");
        }
    }

    public function test_a_southern_or_western_hemisphere_point_is_not_read_as_positive(): void
    {
        // The patterns carry an optional minus sign. Losing it would put a
        // place on the wrong side of the planet and still look plausible.
        $response = $this->assertApiSuccess(
            $this->resolve('https://www.google.com/maps?q=-16.0412,-73.4691')
        );

        $this->assertSame(-16.0412, $response->json('data.latitude'));
        $this->assertSame(-73.4691, $response->json('data.longitude'));
        $this->assertFalse($response->json('data.inside'), 'Peru is not in Sindhudurg');
    }

    // ── The point becomes a stop on the trip ─────────────────────────────────

    public function test_a_pasted_place_is_kept_on_the_trip_and_never_in_sites(): void
    {
        $trip   = $this->cart([]);
        $before = Site::count();

        $this->assertApiSuccess($this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
            'kind'      => 'beach',
        ]));

        $this->assertSame($before, Site::count(), 'An unverified pin must never become a site');

        $stops = $trip->fresh()->custom_stops;
        $this->assertCount(1, $stops);
        $this->assertSame('Nivti Rocks viewpoint', $stops[0]['name']);
        $this->assertSame(16.0412, $stops[0]['latitude']);
        $this->assertArrayHasKey('added_at', $stops[0]);
    }

    public function test_a_pasted_place_needs_a_name_and_real_coordinates(): void
    {
        $trip = $this->cart([]);

        $this->add(['trip_id' => $trip->id, 'latitude' => 16.04, 'longitude' => 73.46])
            ->assertStatus(422);

        $this->add([
            'trip_id' => $trip->id, 'name' => 'Nowhere',
            'latitude' => 999, 'longitude' => 73.46,
        ])->assertStatus(422);

        $this->assertSame([], $trip->fresh()->custom_stops ?? []);
    }

    public function test_a_pasted_place_belonging_to_someone_else_cannot_be_added_to(): void
    {
        $stranger = $this->userWithRole('user');
        $theirs   = SavedTrip::create([
            'user_id' => $stranger->id,
            'status'  => 'draft',
            'days'    => 2,
        ]);

        $this->assertApiFailure($this->add([
            'trip_id'   => $theirs->id,
            'name'      => 'Their trip, my pin',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]));

        $this->assertSame([], $theirs->fresh()->custom_stops ?? []);
    }

    // ── The stop reaches the plan ────────────────────────────────────────────

    public function test_a_pasted_place_appears_in_the_plan_flagged_as_the_travellers_own(): void
    {
        $trip = $this->cart($this->sixPlaces());

        $this->assertApiSuccess($this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]));

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());

        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));

        $mine = collect($this->stops($result['itinerary']))
            ->firstWhere('site.name', 'Nivti Rocks viewpoint');

        $this->assertNotNull($mine, 'The pasted place never reached the plan');
        $this->assertTrue($mine['site']['custom'], 'It must be flagged so the app can badge it');
        $this->assertLessThan(0, $mine['site']['id'], 'It travels on a negative id');
    }

    public function test_a_pasted_place_carries_no_contact_details_it_cannot_have(): void
    {
        $trip = $this->cart($this->sixPlaces());

        $this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]);

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());
        $mine   = collect($this->stops($result['itinerary']))
            ->firstWhere('site.name', 'Nivti Rocks viewpoint');

        // The screen promises no phone and no bus times. Nothing may invent them.
        $this->assertNull($mine['site']['phone']);
        $this->assertNull($mine['site']['whatsapp']);
        $this->assertFalse($mine['site']['verified']);
        $this->assertSame([], $mine['site']['categories']);
    }

    public function test_no_appearance_is_recorded_for_a_pasted_place(): void
    {
        $trip = $this->cart($this->sixPlaces());

        $this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]);

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());
        $this->assertTrue($result['ok']);

        // No business sits behind a pin, so there is no exposure to record —
        // and no row for the foreign key to point at.
        $this->assertSame(
            0,
            TripPlacement::where('trip_id', $trip->id)->where('site_id', '<', 0)->count()
        );
        $this->assertGreaterThan(0, TripPlacement::where('trip_id', $trip->id)->count());
    }

    public function test_several_pasted_places_keep_distinct_ids_and_all_reach_the_plan(): void
    {
        $trip = $this->cart($this->sixPlaces());

        foreach ([['Nivti Rocks', 16.0412], ['Bhogwe cliff', 16.0330]] as [$name, $lat]) {
            $this->add([
                'trip_id'   => $trip->id,
                'name'      => $name,
                'latitude'  => $lat,
                'longitude' => 73.4691,
            ]);
        }

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());
        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));

        $ids = collect($this->stops($result['itinerary']))
            ->pluck('site.id')
            ->filter(fn ($id) => $id < 0)
            ->values();

        $this->assertCount(2, $ids->unique(), 'Two pins must not share an id');

        $names = collect($this->stops($result['itinerary']))->pluck('site.name')->all();
        $this->assertContains('Nivti Rocks', $names);
        $this->assertContains('Bhogwe cliff', $names);
    }

    // ── The cart knows about them ────────────────────────────────────────────

    public function test_a_pasted_place_is_a_row_in_the_cart_like_any_other(): void
    {
        $picked = array_slice($this->sixPlaces(), 0, 2);
        $trip   = $this->cart($picked);

        $this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]);

        $cart = $this->assertApiSuccess($this->cartOf($trip->id));

        // Two places plus one pin is three places in the trip. Before this it
        // reported two, and the traveller who had just been told "added to
        // your trip" was told to add one more.
        $this->assertSame(3, $cart->json('data.count'));
        $this->assertTrue($cart->json('data.can_plan'));

        $places = $cart->json('data.places');
        $this->assertCount(3, $places);

        // Pins sort after the DB rows, the order planFromCart concatenates in.
        $this->assertFalse($places[0]['custom']);
        $this->assertFalse($places[1]['custom']);
        $this->assertTrue($places[2]['custom']);
        $this->assertSame(-1, $places[2]['id']);
        $this->assertSame('Nivti Rocks viewpoint', $places[2]['name']);
        $this->assertNotEmpty($places[2]['key'], 'The app needs a key to remove it by');
        $this->assertNull($places[0]['key'], 'An ordinary place has no key');
    }

    public function test_adding_a_pasted_place_returns_the_whole_cart(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));

        $response = $this->assertApiSuccess($this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]));

        // Every cart endpoint returns the whole cart, so the counter moves at
        // the moment of saving rather than on some other screen's next focus.
        $this->assertSame(3, $response->json('data.count'));
        $this->assertSame((int) $trip->id, $response->json('data.trip_id'));
        $this->assertTrue($response->json('data.places.2.custom'));
    }

    public function test_the_plan_button_and_the_planner_agree_about_pasted_places(): void
    {
        // One real place and two pins: three places, which meets the minimum.
        $trip = $this->cart([$this->sixPlaces()[0]]);

        foreach ([['Nivti Rocks', 16.0412], ['Bhogwe cliff', 16.0330]] as [$name, $lat]) {
            $this->add([
                'trip_id' => $trip->id, 'name' => $name,
                'latitude' => $lat, 'longitude' => 73.4691,
            ]);
        }

        $cart = $this->assertApiSuccess($this->cartOf($trip->id));
        $this->assertTrue($cart->json('data.can_plan'), 'The button offered the trip');

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());

        // The gate must not refuse what the button just offered.
        $this->assertNotSame('cart_too_small', $result['reason'] ?? null);
        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));
    }

    public function test_a_cart_of_only_pasted_places_still_finds_somewhere_to_measure_from(): void
    {
        $this->sixPlaces();                    // candidates exist nearby
        $trip = $this->cart([]);               // but nothing is ticked
        $trip->forceFill(['base_site_id' => null])->save();

        foreach ([['Pin one', 16.0412], ['Pin two', 16.0330], ['Pin three', 16.0500]] as [$name, $lat]) {
            $this->add([
                'trip_id' => $trip->id, 'name' => $name,
                'latitude' => $lat, 'longitude' => 73.4691,
            ]);
        }

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());

        // With no base town and no ticked place, a pin has to be able to
        // anchor the search — otherwise the only allowed ids are negative and
        // there is nothing to fill the days from.
        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));
    }

    // ── Taking one back off ──────────────────────────────────────────────────

    public function test_a_pasted_place_can_be_taken_back_off_the_trip(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));

        $added = $this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Wrong pin',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]);
        $key = $added->json('data.places.2.key');

        $response = $this->assertApiSuccess($this->remove($trip->id, $key));

        $this->assertSame(2, $response->json('data.count'));
        $this->assertSame([], $trip->fresh()->custom_stops);
        $this->assertNotContains(
            'Wrong pin',
            array_column($response->json('data.places'), 'name')
        );
    }

    public function test_removing_the_right_pin_when_several_are_on_the_trip(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));
        $keys = [];

        foreach (['First pin', 'Second pin', 'Third pin'] as $i => $name) {
            $res    = $this->add([
                'trip_id' => $trip->id, 'name' => $name,
                'latitude' => 16.04 + ($i / 100), 'longitude' => 73.4691,
            ]);
            $keys[$name] = $res->json('data.places.' . (2 + $i) . '.key');
        }

        // The middle one. A positional key would take out the wrong place.
        $this->assertApiSuccess($this->remove($trip->id, $keys['Second pin']));

        $names = array_column($this->cartOf($trip->id)->json('data.places'), 'name');
        $this->assertContains('First pin', $names);
        $this->assertContains('Third pin', $names);
        $this->assertNotContains('Second pin', $names);
    }

    public function test_removing_a_pin_that_is_already_gone_is_not_an_error(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));

        // A stale screen should be shown the cart, not a failure for work
        // that is already done.
        $response = $this->assertApiSuccess(
            $this->remove($trip->id, 'a-key-that-was-never-written')
        );

        $this->assertSame(2, $response->json('data.count'));
    }

    public function test_one_traveller_cannot_remove_anothers_pasted_place(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));
        $key  = $this->add([
            'trip_id' => $trip->id, 'name' => 'Mine',
            'latitude' => 16.0412, 'longitude' => 73.4691,
        ])->json('data.places.2.key');

        $stranger = $this->userWithRole('user');
        $this->actingAs($stranger, 'api')
            ->postJson('/api/v2/trip/removeCustomStop', ['trip_id' => $trip->id, 'key' => $key]);

        $this->assertCount(1, $trip->fresh()->custom_stops, 'It must still be there');
    }

    // ── The ceiling ──────────────────────────────────────────────────────────

    public function test_a_trip_takes_only_so_many_pasted_places(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));
        $max  = (int) \App\Models\AiConfig::get('max_custom_stops', 10);

        for ($i = 0; $i < $max; $i++) {
            $this->assertApiSuccess($this->add([
                'trip_id' => $trip->id, 'name' => "Pin {$i}",
                'latitude' => 16.04 + ($i / 1000), 'longitude' => 73.4691,
            ]));
        }

        // Every pin is a mandatory pick, so an unbounded list is a trip that
        // can never be planned.
        $refusal = $this->add([
            'trip_id' => $trip->id, 'name' => 'One too many',
            'latitude' => 16.09, 'longitude' => 73.4691,
        ]);

        $this->assertFalse($refusal->json('success'));
        $this->assertSame('too_many_custom_stops', $refusal->json('data.reason'));
        $this->assertCount($max, $trip->fresh()->custom_stops);
    }

    public function test_presenting_a_pasted_place_costs_no_extra_queries(): void
    {
        $trip = $this->cart(array_slice($this->sixPlaces(), 0, 2));

        for ($i = 0; $i < 3; $i++) {
            $this->add([
                'trip_id' => $trip->id, 'name' => "Pin {$i}",
                'latitude' => 16.04 + ($i / 100), 'longitude' => 73.4691,
            ]);
        }

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->cartOf($trip->id);
        $withPins = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $bare = $this->cart(array_slice($this->sixPlaces(), 0, 2));
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->cartOf($bare->id);
        $withoutPins = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // A pin has no row, so asking for its categories is a query against
        // nothing. One per pin, on a presenter three screens call on focus.
        $this->assertSame(
            $withoutPins,
            $withPins,
            'Each pasted place is costing a categories lookup for a row that does not exist'
        );
    }

    // ── What the stored plan remembers ───────────────────────────────────────

    public function test_the_stored_plan_remembers_which_stops_were_the_travellers_own(): void
    {
        $picked = $this->sixPlaces();
        $trip   = $this->cart($picked);

        $result = app(TripPlanner::class)->planFromCart($trip);
        $this->assertTrue($result['ok'], $result['reason'] ?? '');

        $byId = collect($this->stops($result['itinerary']))->keyBy('site.id');

        // The badge used to be read off the live cart, which a reopened trip
        // no longer has — so a saved plan lost every YOURS marker.
        foreach ($picked as $site) {
            $this->assertTrue(
                $byId[$site->id]['site']['picked'],
                "{$site->name} was the traveller's own pick and must say so"
            );
        }

        $suggested = $byId->reject(
            fn ($stop, $id) => in_array($id, array_map(fn ($s) => $s->id, $picked), true)
        );
        $this->assertNotEmpty($suggested, 'The planner should have filled with suggestions');
        foreach ($suggested as $stop) {
            $this->assertFalse(
                $stop['site']['picked'],
                'A suggestion must not be badged as the traveller\'s choice'
            );
        }
    }

    public function test_a_stop_carries_both_category_names_so_marathi_reads_as_marathi(): void
    {
        $trip   = $this->cart($this->sixPlaces());
        $result = app(TripPlanner::class)->planFromCart($trip);

        $this->assertTrue($result['ok'], $result['reason'] ?? '');

        $stop = collect($this->stops($result['itinerary']))
            ->first(fn ($s) => $s['site']['categories'] !== []);

        $this->assertNotNull($stop, 'No stop carried a category at all');

        // The itinerary is STORED. `$c->name` resolves through Category's
        // accessor against the generating account's language, so whichever
        // language planned the trip was frozen into it — and mr_name was not
        // even selected, so the Marathi branch could never fire and every
        // saved plan carried English. TripStopDetailScreen and
        // TripContactsScreen both render these through categoryNames().
        $this->assertSame('Beach', $stop['site']['categories'][0]['name']);
        $this->assertSame('समुद्रकिनारा', $stop['site']['categories'][0]['mr_name']);
        $this->assertSame('beach', $stop['site']['categories'][0]['code']);
    }

    public function test_a_pasted_place_is_measured_from_when_a_day_needs_filling(): void
    {
        // Three picks over two days cannot meet a two-stop minimum alone, so
        // the top-up runs. One of the picks is a pin, which has no row — the
        // coordinate lookup returned nothing for it and the nearest-place
        // choice silently became "whatever was first".
        $picked = array_slice($this->sixPlaces(), 0, 2);
        $trip   = $this->cart($picked);

        $this->add([
            'trip_id'   => $trip->id,
            'name'      => 'Nivti Rocks viewpoint',
            'latitude'  => 16.0412,
            'longitude' => 73.4691,
        ]);

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());

        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));

        // Every day met the minimum, and the pin survived as a required pick.
        foreach ($result['itinerary']['days'] as $day) {
            $this->assertGreaterThanOrEqual(2, count($day['stops']));
        }
        $this->assertContains(
            'Nivti Rocks viewpoint',
            collect($this->stops($result['itinerary']))->pluck('site.name')->all()
        );
    }

    public function test_more_places_than_the_days_can_hold_is_refused_before_the_model(): void
    {
        $max      = (int) \App\Models\AiConfig::get('max_stops_per_day', 5);
        $capacity = 1 * $max;                        // a one-day trip

        $sites = [];
        for ($i = 0; $i <= $capacity; $i++) {        // one more than fits
            $sites[] = $this->site("Place {$i}", 16.04 + ($i / 500), 73.47);
        }

        $trip = $this->cart($sites, days: 1);

        $before = \App\Models\AiLog::count();
        $result = app(TripPlanner::class)->planFromCart($trip);

        // Every pick is mandatory, so this call could only ever come back as
        // picked_place_missing. Refusing costs nothing; calling costs a credit
        // and tells the traveller the wrong thing about why.
        $this->assertFalse($result['ok']);
        $this->assertSame('cart_too_big', $result['reason']);
        $this->assertSame($before, \App\Models\AiLog::count(), 'No model call should have been made');
    }

    public function test_the_fake_driver_never_drops_a_place_the_traveller_picked(): void
    {
        // The fake is the default driver, so a dev or a CI run exercises it.
        // It sliced the combined list to days x max_stops_per_day, and the
        // planner concatenates pins LAST — so pins were the first thing cut,
        // and the assembler calls a missing pick fatal.
        $max   = (int) \App\Models\AiConfig::get('max_stops_per_day', 5);
        $sites = [];
        for ($i = 0; $i < $max; $i++) {
            $sites[] = $this->site("Place {$i}", 16.04 + ($i / 500), 73.47);
        }
        $this->site('Spare one', 16.07, 73.47);
        $this->site('Spare two', 16.08, 73.47);

        $trip = $this->cart($sites, days: 2);
        $this->add([
            'trip_id' => $trip->id, 'name' => 'Nivti Rocks viewpoint',
            'latitude' => 16.0412, 'longitude' => 73.4691,
        ]);

        $result = app(TripPlanner::class)->planFromCart($trip->fresh());

        $this->assertTrue($result['ok'], 'Planning failed: ' . ($result['reason'] ?? ''));
        $this->assertContains(
            'Nivti Rocks viewpoint',
            collect($this->stops($result['itinerary']))->pluck('site.name')->all(),
            'The pasted pin was dropped by the stand-in model'
        );
    }
}
