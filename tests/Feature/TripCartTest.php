<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favourite;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * The cart — favourites that share a trip.
 *
 * It ships before generation exists and is useful on its own, so these cover
 * the whole surface a traveller touches: ticking, removing, ordering, and the
 * boundary that keeps one person's trip out of another's hands.
 */
class TripCartTest extends ApiTestCase
{
    private User $traveller;
    private Category $beach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->userWithRole('user');

        $this->beach = Category::create([
            'name' => 'Beach', 'mr_name' => 'समुद्रकिनारा', 'code' => 'beach',
            'icon' => '', 'status' => true,
        ]);
    }

    private function site(
        string $name,
        ?string $busStopType = null,
        bool $approved = true
    ): Site {
        $site = Site::create([
            'name'              => $name,
            'description'       => 'A Konkan place used for testing the cart.',
            'user_id'           => $this->traveller->id,
            'status'            => true,
            'submission_status' => $approved ? 'approved' : 'pending',
            'latitude'          => 16.38,
            'longitude'         => 73.38,
            'bus_stop_type'     => $busStopType,
        ]);

        $site->categories()->attach($this->beach->id);

        return $site;
    }

    private function add(Site $site, ?int $tripId = null)
    {
        return $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/addToCart', array_filter([
            'site_id' => $site->id,
            'trip_id' => $tripId,
        ]));
    }

    // ── Ticking places ───────────────────────────────────────────────────────

    public function test_ticking_a_place_starts_a_trip_and_adds_it(): void
    {
        $response = $this->assertApiSuccess($this->add($this->site('Kunkeshwar Beach')));

        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertNotNull($response->json('data.trip_id'));
        $this->assertSame('Kunkeshwar Beach', $response->json('data.places.0.name'));
    }

    public function test_ticking_the_same_place_twice_leaves_one_row(): void
    {
        $site = $this->site('Kunkeshwar Beach');

        $first = $this->assertApiSuccess($this->add($site));
        $again = $this->assertApiSuccess($this->add($site, $first->json('data.trip_id')));

        $this->assertSame(1, $again->json('data.count'));
        $this->assertSame(1, Favourite::whereNotNull('trip_id')->count());
    }

    public function test_everything_lands_in_one_open_cart_while_browsing(): void
    {
        // No trip_id sent — the common case, ticking places as you scroll.
        $this->add($this->site('Kunkeshwar Beach'));
        $this->add($this->site('Vijaydurg Fort'));
        $last = $this->assertApiSuccess($this->add($this->site('Aare Ware Beach')));

        $this->assertSame(3, $last->json('data.count'));
        $this->assertSame(1, SavedTrip::count(), 'Browsing must not open a new trip per place');
    }

    // ── What may not go in ───────────────────────────────────────────────────

    public function test_a_bus_stop_cannot_be_added_to_a_trip(): void
    {
        $response = $this->assertApiFailure($this->add($this->site('Devgad ST Stand', busStopType: 'depot')));

        $this->assertSame('not_a_destination', $response->json('data.reason'));
        $this->assertSame(0, Favourite::count());
    }

    public function test_an_unapproved_place_cannot_be_added(): void
    {
        $this->assertApiFailure($this->add($this->site('Unapproved Shack', approved: false)));

        $this->assertSame(0, Favourite::count());
    }

    public function test_an_unknown_place_is_refused_by_validation(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->traveller, 'api')
                ->postJson('/api/v2/trip/addToCart', ['site_id' => 999999])
        );
    }

    // ── Managing the cart ────────────────────────────────────────────────────

    public function test_a_place_can_be_removed(): void
    {
        $keep   = $this->site('Kunkeshwar Beach');
        $remove = $this->site('Vijaydurg Fort');

        $trip = $this->add($keep)->json('data.trip_id');
        $this->add($remove, $trip);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/removeFromCart', [
                'trip_id' => $trip,
                'site_id' => $remove->id,
            ])
        );

        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame($keep->id, $response->json('data.places.0.id'));
    }

    public function test_the_traveller_can_reorder_their_cart(): void
    {
        $a = $this->site('First added');
        $b = $this->site('Second added');
        $c = $this->site('Third added');

        $trip = $this->add($a)->json('data.trip_id');
        $this->add($b, $trip);
        $this->add($c, $trip);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/reorderCart', [
                'trip_id'  => $trip,
                'site_ids' => [$c->id, $a->id, $b->id],
            ])
        );

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            array_column($response->json('data.places'), 'id')
        );
    }

    public function test_days_and_base_town_can_be_set(): void
    {
        $site = $this->site('Kunkeshwar Beach');
        $trip = $this->add($site)->json('data.trip_id');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/updateCart', [
                'trip_id'      => $trip,
                'days'         => 3,
                'base_site_id' => $site->id,
                'title'        => 'Konkan in October',
            ])
        );

        $this->assertSame(3, $response->json('data.days'));
        $this->assertSame($site->id, $response->json('data.base_site_id'));
        $this->assertSame('Konkan in October', $response->json('data.title'));
    }

    public function test_an_absurd_day_count_is_refused(): void
    {
        $trip = $this->add($this->site('Kunkeshwar Beach'))->json('data.trip_id');

        $this->assertApiFailure(
            $this->actingAs($this->traveller, 'api')
                ->postJson('/api/v2/trip/updateCart', ['trip_id' => $trip, 'days' => 90])
        );
    }

    // ── The rule the Plan button binds to ────────────────────────────────────

    public function test_can_plan_turns_on_at_the_minimum(): void
    {
        $trip = $this->add($this->site('One'))->json('data.trip_id');

        $this->assertFalse($this->add($this->site('Two'), $trip)->json('data.can_plan'));

        $third = $this->add($this->site('Three'), $trip);

        $this->assertTrue($third->json('data.can_plan'));
        $this->assertSame(3, $third->json('data.minimum'));
    }

    // ── One traveller's cart is not another's ────────────────────────────────

    public function test_a_cart_cannot_be_read_by_someone_else(): void
    {
        $trip = $this->add($this->site('Kunkeshwar Beach'))->json('data.trip_id');

        $response = $this->actingAs($this->userWithRole('user'), 'api')
            ->postJson('/api/v2/trip/cart', ['trip_id' => $trip]);

        $response->assertStatus(404);
    }

    public function test_a_cart_cannot_be_changed_by_someone_else(): void
    {
        $site = $this->site('Kunkeshwar Beach');
        $trip = $this->add($site)->json('data.trip_id');

        $this->actingAs($this->userWithRole('user'), 'api')
            ->postJson('/api/v2/trip/removeFromCart', ['trip_id' => $trip, 'site_id' => $site->id])
            ->assertStatus(404);

        $this->assertSame(1, Favourite::whereNotNull('trip_id')->count());
    }

    public function test_the_cart_needs_authentication(): void
    {
        $this->postJson('/api/v2/trip/cart')->assertStatus(401);
    }

    // ── The reuse that makes this cheap ──────────────────────────────────────

    public function test_an_ordinary_favourite_is_untouched_by_the_cart(): void
    {
        $site = $this->site('Kunkeshwar Beach');

        // A plain favourite, as the existing screen creates it.
        Favourite::create([
            'user_id'           => $this->traveller->id,
            'favouritable_type' => (new Site())->getMorphClass(),
            'favouritable_id'   => $site->id,
        ]);

        $response = $this->assertApiSuccess($this->add($site));

        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame(
            1,
            Favourite::whereNull('trip_id')->count(),
            'The existing favourite must survive untouched'
        );
    }
}
