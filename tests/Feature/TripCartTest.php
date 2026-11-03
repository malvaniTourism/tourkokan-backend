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

    /** The open cart, or a named trip. */
    private function cart(?int $tripId = null)
    {
        return $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/trip/cart', array_filter(['trip_id' => $tripId]));
    }

    /** The trip a site was ticked into. */
    private function tripId(Site $site): int
    {
        return (int) Favourite::withoutGlobalScopes()
            ->where('favouritable_id', $site->id)
            ->whereNotNull('trip_id')
            ->value('trip_id');
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

    /**
     * The cart lives on `favourites`, which eight other queries already read
     * and none of them knew about `trip_id`. Both halves of that mistake are
     * pinned here: a ticked place is not a favourite, and the heart must not
     * reach into somebody's trip.
     */
    public function test_a_place_in_a_trip_is_not_a_favourite(): void
    {
        $site = $this->site('Tarkarli Beach');
        $this->assertApiSuccess($this->add($site));

        $listed = $this->actingAs($this->traveller, 'api')
            ->postJson('/api/v2/favourites');

        $this->assertApiSuccess($listed);
        $this->assertSame(
            0,
            $listed->json('data.total'),
            'Ticking a place into a trip must not add it to Favourites'
        );
    }

    public function test_tapping_the_heart_cannot_remove_a_place_from_a_trip(): void
    {
        $site = $this->site('Tarkarli Beach');
        $trip = $this->assertApiSuccess($this->add($site))->json('data.trip_id');

        // The heart toggle, exactly as the favourites endpoint runs it.
        $found = \App\Models\Site::find($site->id)->favourites()
            ->where(['user_id' => $this->traveller->id])
            ->first();

        $this->assertNull($found, 'The toggle must not see a cart row at all');
        $this->assertSame(1, \App\Models\Favourite::inTrip($trip)->count());
    }

    public function test_a_place_keeps_the_position_it_was_added_at(): void
    {
        // `sort_order` was missing from $fillable, so mass assignment dropped
        // it and every cart row was created with a null position.
        $first  = $this->site('Tarkarli Beach');
        $second = $this->site('Chivla Beach');

        $trip = $this->assertApiSuccess($this->add($first))->json('data.trip_id');
        $this->assertApiSuccess($this->add($second, $trip));

        $orders = \App\Models\Favourite::inTrip($trip)->orderBy('id')->pluck('sort_order');

        $this->assertNotNull($orders[1], 'The second place must carry a position');
        $this->assertGreaterThan($orders[0] ?? -1, $orders[1]);
    }

    public function test_ticking_the_same_place_twice_leaves_one_row(): void
    {
        $site = $this->site('Kunkeshwar Beach');

        $first = $this->assertApiSuccess($this->add($site));
        $again = $this->assertApiSuccess($this->add($site, $first->json('data.trip_id')));

        $this->assertSame(1, $again->json('data.count'));
        $this->assertSame(1, Favourite::inTrip()->count());
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

        $this->assertSame(1, Favourite::inTrip()->count());
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

    // ── The number beside "Make my plan" ─────────────────────────────────────

    public function test_the_cart_reports_how_many_free_plans_are_left(): void
    {
        $response = $this->assertApiSuccess($this->add($this->site('Kunkeshwar Beach')));

        // The review screen shows this right before the traveller spends one.
        // It used to read whatever the last unrelated request left in the
        // store — the step-1 options call — so the number belonged to a
        // different moment.
        $this->assertNotNull(
            $response->json('data.credits'),
            'Every cart endpoint reports the whole cart, credits included'
        );
    }

    public function test_a_traveller_who_has_never_planned_is_not_told_they_have_none(): void
    {
        $free = (int) \App\Models\AiConfig::get('free_plans_on_signup', 3);

        // The signup grant is lazy: it is made on the first generation,
        // behind the verified-email gate. So the wallet really is empty here.
        $this->assertSame(0, app(\App\Services\Credits\CreditLedger::class)->balance($this->traveller));

        $response = $this->assertApiSuccess($this->add($this->site('Tarkarli Beach')));

        // Showing 0 beside a button that works, and then granting three the
        // moment it is pressed, is a lie in the discouraging direction.
        $this->assertSame($free, $response->json('data.credits'));
    }

    public function test_reporting_the_balance_does_not_hand_out_the_grant(): void
    {
        $ledger = app(\App\Services\Credits\CreditLedger::class);

        $this->assertApiSuccess($this->add($this->site('Devbag Beach')));
        $this->assertApiSuccess($this->add($this->site('Chivla Beach')));

        // Reading a cart must not give anyone credits — a guest can open one
        // and could never reach the endpoint that spends them.
        $this->assertFalse($ledger->hasGrant($this->traveller, 'signup'));
        $this->assertSame(0, $ledger->balance($this->traveller));
    }

    public function test_what_is_shown_before_planning_matches_what_is_left_after(): void
    {
        $shown = $this->assertApiSuccess($this->add($this->site('Bhogwe Beach')))
            ->json('data.credits');

        $ledger = app(\App\Services\Credits\CreditLedger::class);
        $ledger->grantSignupCredits($this->traveller);   // what generating does
        $ledger->spend($this->traveller, 99);            // and then one is spent

        $this->assertSame(
            $shown - 1,
            $ledger->balance($this->traveller),
            'The count shown before planning must be the one planning starts from'
        );
    }

    // ── A place that stopped being plannable ─────────────────────────────────

    public function test_a_picked_place_that_is_no_longer_listed_is_reported_not_swallowed(): void
    {
        $keep = $this->site('Tarkarli Beach');
        $gone = $this->site('Doomed Beach');

        $this->assertApiSuccess($this->add($keep));
        $response = $this->assertApiSuccess($this->add($gone, $this->tripId($keep)));
        $this->assertSame(2, $response->json('data.count'));
        $this->assertSame(0, $response->json('data.unavailable'));

        // It stops being somewhere you can go — unapproved, in this case.
        $gone->forceFill(['submission_status' => 'pending'])->save();

        $cart = $this->assertApiSuccess($this->cart());

        // contents() filters it out, so it leaves the list either way. What
        // must not happen is leaving with no explanation.
        $this->assertSame(1, $cart->json('data.count'));
        $this->assertSame(
            1,
            $cart->json('data.unavailable'),
            'A place the traveller ticked vanished and nothing said so'
        );
    }

    public function test_a_cart_whose_places_are_all_fine_reports_none_unavailable(): void
    {
        $this->assertApiSuccess($this->add($this->site('Chivla Beach')));

        $this->assertSame(0, $this->cart()->json('data.unavailable'));
    }

    public function test_a_bus_stop_a_place_later_became_is_reported_too(): void
    {
        $site = $this->site('Was A Beach');
        $this->assertApiSuccess($this->add($site));

        // Retagged as a shelter by an admin: still listed, no longer a
        // destination, so the planner would refuse to build around it.
        $site->forceFill(['bus_stop_type' => 'depot'])->save();

        $cart = $this->assertApiSuccess($this->cart());
        $this->assertSame(0, $cart->json('data.count'));
        $this->assertSame(1, $cart->json('data.unavailable'));
    }

    // ── Order stays dense ────────────────────────────────────────────────────

    public function test_reordering_round_an_id_that_matches_nothing_leaves_no_gaps(): void
    {
        $a = $this->site('Alpha Beach');
        $b = $this->site('Bravo Beach');
        $c = $this->site('Charlie Beach');

        $this->assertApiSuccess($this->add($a));
        $trip = $this->tripId($a);
        $this->assertApiSuccess($this->add($b, $trip));
        $this->assertApiSuccess($this->add($c, $trip));

        // 999 is in the cart's list but not in the cart — a stale client, or
        // the negative id of a pasted pin.
        $this->actingAs($this->traveller, 'api')->postJson('/api/v2/trip/reorderCart', [
            'trip_id'  => $trip,
            'site_ids' => [$c->id, 999, $a->id, $b->id],
        ])->assertOk();

        $orders = Favourite::withoutGlobalScopes()
            ->where('trip_id', $trip)
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all();

        // Dense from zero. An unmatched id used to burn a slot, leaving a
        // hole in a sequence the next reader may assume is contiguous.
        $this->assertSame([0, 1, 2], array_map('intval', $orders));

        // And the order the traveller asked for still holds.
        $ids = array_column($this->cart()->json('data.places'), 'id');
        $this->assertSame([$c->id, $a->id, $b->id], $ids);
    }
}
