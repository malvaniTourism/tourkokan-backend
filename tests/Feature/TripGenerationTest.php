<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\TripPlacement;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Trip\Ai\AiClient;
use Tests\ApiTestCase;

/**
 * Generation over HTTP: the gate, the quota, and the placement rows.
 *
 * The three things that have to be true before this endpoint is safe to
 * expose — an identity, a budget, and a rate limit — are all exercised here,
 * along with the rows the vendor sales pitch will later be built from.
 */
class TripGenerationTest extends ApiTestCase
{
    private User $traveller;
    private Category $beach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->verifiedUser();

        $this->beach = Category::create([
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true,
        ]);
    }

    private function verifiedUser(): User
    {
        $user = $this->userWithRole('user');
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->fresh();
    }

    private function site(string $name, float $lat = 16.38, float $lng = 73.38): Site
    {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing generation.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => $lat,
            'longitude'         => $lng,
        ]);

        $site->categories()->attach($this->beach->id);

        return $site;
    }

    /** A draft trip with four picked places and two more nearby to suggest. */
    private function filledCart(?User $owner = null, int $days = 2): SavedTrip
    {
        $owner ??= $this->traveller;

        $trip = SavedTrip::create([
            'user_id' => $owner->id,
            'status'  => 'draft',
            'days'    => $days,
            'filters' => ['category_codes' => ['beach'], 'radius_km' => 40],
        ]);

        foreach (['Kunkeshwar Beach', 'Vijaydurg Fort', 'Aare Ware Beach', 'Mithbav Jetty'] as $i => $name) {
            Favourite::create([
                'user_id'           => $owner->id,
                'trip_id'           => $trip->id,
                'sort_order'        => $i,
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => $this->site($name)->id,
            ]);
        }

        // Two more in range for the planner to offer as suggestions.
        $this->site('Taramumbri Beach');
        $this->site('Kokan Katta');

        return $trip->fresh();
    }

    private function plan(?User $as = null, ?int $tripId = null)
    {
        return $this->actingAs($as ?? $this->traveller, 'api')
            ->postJson('/api/v2/trip/planFromCart', ['trip_id' => $tripId]);
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_verified_traveller_can_plan_their_cart(): void
    {
        $trip = $this->filledCart();

        $response = $this->assertApiSuccess($this->plan(tripId: $trip->id));

        $this->assertSame(2, $response->json('data.itinerary.trip.days'));
        $this->assertCount(2, $response->json('data.itinerary.days'));
        $this->assertSame('generated', $trip->fresh()->status);
        $this->assertNotNull($trip->fresh()->itinerary);
    }

    // ── The gate: a token is not an identity ─────────────────────────────────

    public function test_a_guest_is_asked_to_save_their_trip_first(): void
    {
        // Exactly what /register with is_guest produces: a working JWT and
        // no email.
        $guest = $this->userWithRole('user');
        $guest->forceFill(['email_verified_at' => null])->save();

        $trip = $this->filledCart($guest->fresh());

        $response = $this->assertApiFailure($this->plan($guest->fresh(), $trip->id));

        $this->assertSame('verification_required', $response->json('data.reason'));
        $this->assertSame('draft', $trip->fresh()->status, 'Nothing should have been generated');
    }

    public function test_generation_needs_authentication(): void
    {
        $this->postJson('/api/v2/trip/planFromCart', ['trip_id' => 1])->assertStatus(401);
    }

    public function test_another_travellers_trip_cannot_be_planned(): void
    {
        $trip = $this->filledCart();

        $this->plan($this->verifiedUser(), $trip->id)->assertStatus(404);
        $this->assertSame('draft', $trip->fresh()->status);
    }

    // ── The quota ────────────────────────────────────────────────────────────

    public function test_planning_spends_one_of_the_free_plans(): void
    {
        $credits = app(CreditLedger::class);
        $trip    = $this->filledCart();

        $this->assertApiSuccess($this->plan(tripId: $trip->id));

        // Three granted on first use, one spent.
        $this->assertSame(2, $credits->balance($this->traveller));
    }

    public function test_replanning_the_same_trip_is_free(): void
    {
        $credits = app(CreditLedger::class);
        $trip    = $this->filledCart();

        $this->assertApiSuccess($this->plan(tripId: $trip->id));
        $after = $credits->balance($this->traveller);

        $this->assertApiSuccess($this->plan(tripId: $trip->id));

        $this->assertSame(
            $after,
            $credits->balance($this->traveller),
            'One credit buys a trip and every re-plan of it'
        );
    }

    public function test_running_out_of_plans_refuses_rather_than_generating(): void
    {
        $credits = app(CreditLedger::class);

        // Burn the signup grant on three separate trips.
        foreach (range(1, 3) as $n) {
            $this->assertApiSuccess($this->plan(tripId: $this->filledCart()->id));
        }

        $this->assertSame(0, $credits->balance($this->traveller));

        $fourth   = $this->filledCart();
        $response = $this->assertApiFailure($this->plan(tripId: $fourth->id));

        $this->assertSame('insufficient_credits', $response->json('data.reason'));
        $this->assertSame('draft', $fourth->fresh()->status);
    }

    public function test_a_failed_generation_gives_the_credit_back(): void
    {
        $credits = app(CreditLedger::class);
        $trip    = $this->filledCart();

        // A model that returns a day too thin to serve.
        $this->app->bind(AiClient::class, fn () => new class implements AiClient {
            public function itinerary(array $prompt, array $schema): array
            {
                return [
                    'data' => [
                        'trip' => ['title' => 'Trip', 'summary' => ''],
                        'days' => [[
                            'day' => 1, 'theme' => 'One', 'stops' => [[
                                'site_id' => $prompt['allowed_site_ids'][0],
                                'arrive'  => '09:00', 'depart' => '11:00', 'note' => 'only one',
                            ]],
                        ]],
                        'warnings' => [],
                    ],
                    'meta' => ['driver' => 'stub'],
                ];
            }
        });

        $response = $this->assertApiFailure($this->plan(tripId: $trip->id));

        $this->assertSame('generation_failed', $response->json('data.reason'));
        $this->assertSame(3, $credits->balance($this->traveller), 'A failure must not cost a plan');
        $this->assertSame(3, $response->json('data.credits'));
    }

    // ── The rows the vendor pitch is built from ──────────────────────────────

    public function test_every_appearance_in_an_itinerary_is_recorded(): void
    {
        $trip = $this->filledCart();

        $response = $this->assertApiSuccess($this->plan(tripId: $trip->id));

        $stops = collect($response->json('data.itinerary.days'))
            ->flatMap(fn ($d) => $d['stops'])
            ->count();

        $this->assertSame(
            $stops,
            TripPlacement::where('trip_id', $trip->id)->count(),
            'One placement row per stop — this is what "appeared in 40 itineraries" counts'
        );

        $row = TripPlacement::where('trip_id', $trip->id)->first();
        $this->assertSame($this->traveller->id, $row->user_id);
        $this->assertGreaterThanOrEqual(1, $row->day);
    }

    public function test_replanning_replaces_appearances_rather_than_stacking_them(): void
    {
        $trip = $this->filledCart();

        $this->assertApiSuccess($this->plan(tripId: $trip->id));
        $first = TripPlacement::where('trip_id', $trip->id)->count();

        $this->assertApiSuccess($this->plan(tripId: $trip->id));

        $this->assertSame(
            $first,
            TripPlacement::where('trip_id', $trip->id)->count(),
            'Five re-plans must not read as five exposures for every business'
        );
    }

    public function test_a_failed_generation_records_no_appearances(): void
    {
        $trip = $this->filledCart();

        $this->app->bind(AiClient::class, fn () => new class implements AiClient {
            public function itinerary(array $prompt, array $schema): array
            {
                throw new \RuntimeException('model unreachable');
            }
        });

        $this->assertApiFailure($this->plan(tripId: $trip->id));

        $this->assertSame(0, TripPlacement::count());
    }

    // ── Refusals that cost nothing ───────────────────────────────────────────

    public function test_a_cart_below_the_minimum_costs_no_credit(): void
    {
        $credits = app(CreditLedger::class);

        $trip = SavedTrip::create([
            'user_id' => $this->traveller->id,
            'status'  => 'draft',
            'days'    => 2,
        ]);
        Favourite::create([
            'user_id'           => $this->traveller->id,
            'trip_id'           => $trip->id,
            'favouritable_type' => (new Site())->getMorphClass(),
            'favouritable_id'   => $this->site('Lonely Beach')->id,
        ]);

        $response = $this->assertApiFailure($this->plan(tripId: $trip->id));

        $this->assertSame('cart_too_small', $response->json('data.reason'));
        $this->assertSame(3, $credits->balance($this->traveller));
    }
}
