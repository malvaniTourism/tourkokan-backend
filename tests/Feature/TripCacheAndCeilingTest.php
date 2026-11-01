<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiItineraryCache;
use App\Models\AiLog;
use App\Models\Category;
use App\Models\SavedTrip;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Tests\ApiTestCase;

/**
 * The two things that bound what this feature costs.
 *
 * The cache is the lever: the same question is never bought twice. The ceiling
 * is the circuit breaker: when the day's spend crosses it, a new question is
 * declined while already-answered ones keep working.
 *
 * The property worth defending hardest is that a cached plan is never staler
 * than a fresh one. It holds because the cache keeps the model's decision and
 * the places are refetched and revalidated on every hit — so a business that
 * has closed falls out of a cached plan the same way an invented one would.
 */
class TripCacheAndCeilingTest extends ApiTestCase
{
    private User $traveller;
    private Site $town;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = $this->verifiedUser();

        $credits = app(CreditLedger::class);
        // Taken here rather than left to the first plan call, which grants it
        // on the way past and would otherwise move a balance mid-assertion.
        $credits->grantSignupCredits($this->traveller);
        $credits->grant($this->traveller, 50, 'test');

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

        $this->town = $this->site('Malvan', 'city');
        $this->beaches(10);
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
            'description'       => 'A Konkan place used for testing the cache.',
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
            $this->site("Beach {$i}", 'beach', 16.06 + ($i * 0.005), 73.47);
        }
    }

    private function filters(int $days = 2, array $codes = ['beach']): array
    {
        return [
            'town_site_ids'  => [$this->town->id],
            'category_codes' => $codes,
            'days'           => $days,
        ];
    }

    private function plan(array $filters, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->traveller, 'api')
            ->postJson('/api/v2/trip/plan', $filters);
    }

    /** @return array<int,string> every place named in a response's plan */
    private function stopNames($response): array
    {
        return collect($response->json('data.itinerary.days'))
            ->flatMap(fn ($day) => collect($day['stops'])->pluck('site.name'))
            ->all();
    }

    // ── The cache ────────────────────────────────────────────────────────────

    public function test_the_same_question_is_only_paid_for_once(): void
    {
        $this->assertApiSuccess($this->plan($this->filters()));
        $this->assertSame(1, AiItineraryCache::count());

        $this->assertApiSuccess($this->plan($this->filters()));

        $this->assertSame(
            'cached',
            AiLog::latest('id')->first()->status,
            'A repeated question must be served from the cache'
        );
        $this->assertSame(1, AiItineraryCache::first()->hits);
    }

    public function test_a_cache_hit_still_costs_the_traveller_a_plan(): void
    {
        $credits = app(CreditLedger::class);

        $this->assertApiSuccess($this->plan($this->filters()));
        $after = $credits->balance($this->traveller);

        $this->assertApiSuccess($this->plan($this->filters()));

        // Free to us, but they received a plan — and the quota is about what
        // they got, not about what it cost us.
        $this->assertSame($after - 1, $credits->balance($this->traveller));
    }

    public function test_a_different_question_is_not_served_the_cached_answer(): void
    {
        $this->assertApiSuccess($this->plan($this->filters(days: 2)));
        $this->assertApiSuccess($this->plan($this->filters(days: 3)));

        $this->assertSame('success', AiLog::latest('id')->first()->status);
        $this->assertSame(2, AiItineraryCache::count());
    }

    /**
     * The property the whole design rests on.
     */
    public function test_a_place_that_closes_cannot_survive_in_a_cached_plan(): void
    {
        $first = $this->assertApiSuccess($this->plan($this->filters()));
        $names = $this->stopNames($first);

        $closed = Site::where('name', $names[0])->first();
        $closed->forceFill(['submission_status' => 'pending'])->save();

        $second = $this->assertApiSuccess($this->plan($this->filters()));

        $this->assertSame('cached', AiLog::latest('id')->first()->status);
        $this->assertNotContains(
            $closed->name,
            $this->stopNames($second),
            'A cached plan must not name a business that has since closed'
        );
    }

    public function test_an_answer_that_no_longer_validates_is_replaced(): void
    {
        $this->assertApiSuccess($this->plan($this->filters()));
        $this->assertSame(1, AiItineraryCache::count());

        // Every place the answer named closes, and new ones open in their
        // stead. The question is unchanged, so the cache is consulted — and
        // what it holds now names nothing that exists.
        Site::where('name', 'like', 'Beach %')->update(['submission_status' => 'pending']);
        for ($i = 0; $i < 10; $i++) {
            $this->site("Fresh {$i}", 'beach', 16.06 + ($i * 0.005), 73.47);
        }

        $response = $this->assertApiSuccess($this->plan($this->filters()));

        $this->assertSame(
            'success',
            AiLog::latest('id')->first()->status,
            'A dead answer must trigger a fresh generation, not be served'
        );

        foreach ($this->stopNames($response) as $name) {
            $this->assertStringStartsWith('Fresh', $name);
        }
    }

    public function test_caching_can_be_switched_off_without_a_deploy(): void
    {
        AiConfig::set('cache_enabled', 'false');

        $this->assertApiSuccess($this->plan($this->filters()));
        $this->assertApiSuccess($this->plan($this->filters()));

        $this->assertSame(0, AiItineraryCache::count());
        $this->assertSame('success', AiLog::latest('id')->first()->status);
    }

    public function test_a_cart_plan_is_never_cached(): void
    {
        // A cart's input set belongs to one traveller, so two of them would
        // take turns throwing each other's answer away.
        $trip = SavedTrip::create([
            'user_id' => $this->traveller->id,
            'status'  => 'draft',
            'days'    => 2,
        ]);

        foreach (Site::where('name', 'like', 'Beach %')->limit(4)->get() as $i => $site) {
            \App\Models\Favourite::create([
                'user_id'           => $this->traveller->id,
                'trip_id'           => $trip->id,
                'sort_order'        => $i,
                'favouritable_type' => (new Site())->getMorphClass(),
                'favouritable_id'   => $site->id,
            ]);
        }

        $this->assertApiSuccess(
            $this->actingAs($this->traveller, 'api')
                ->postJson('/api/v2/trip/planFromCart', ['trip_id' => $trip->id])
        );

        $this->assertSame(0, AiItineraryCache::count());
    }

    // ── The ceiling ──────────────────────────────────────────────────────────

    public function test_the_ceiling_declines_a_new_question(): void
    {
        AiConfig::set('daily_spend_ceiling', 0);

        $response = $this->assertApiFailure($this->plan($this->filters()));

        $this->assertSame('spend_ceiling', $response->json('data.reason'));
        $this->assertSame('spend_ceiling', AiLog::latest('id')->first()->failure_reason);
    }

    public function test_the_ceiling_does_not_stop_an_already_answered_question(): void
    {
        $this->assertApiSuccess($this->plan($this->filters()));

        AiConfig::set('daily_spend_ceiling', 0);

        // This is what "degrades gracefully" means: the traveller asking a
        // popular question never learns that spending stopped.
        $this->assertApiSuccess($this->plan($this->filters()));
        $this->assertSame('cached', AiLog::latest('id')->first()->status);
    }

    public function test_being_refused_by_the_ceiling_costs_no_credit(): void
    {
        AiConfig::set('daily_spend_ceiling', 0);

        $credits = app(CreditLedger::class);
        $before  = $credits->balance($this->traveller);

        $this->assertApiFailure($this->plan($this->filters()));

        $this->assertSame($before, $credits->balance($this->traveller));
        $this->assertSame(0, SavedTrip::count(), 'And leaves no trip behind');
    }

    public function test_the_ceiling_counts_what_was_actually_spent(): void
    {
        AiConfig::set('daily_spend_ceiling', 10);

        // The fake driver is free, so nothing can ever cross a ceiling above
        // zero — which is itself the right behaviour to pin down: a free
        // driver must never be throttled for cost.
        AiLog::create([
            'user_id' => $this->traveller->id,
            'type'    => 'plan',
            'status'  => 'success',
            'cost_inr' => 9.5,
        ]);

        $guard = app(\App\Services\Trip\SpendGuard::class);
        $this->assertSame(9.5, $guard->spentToday());
        $this->assertTrue($guard->canSpend());

        AiLog::create([
            'user_id'  => $this->traveller->id,
            'type'     => 'plan',
            'status'   => 'success',
            'cost_inr' => 1.0,
        ]);

        $this->assertFalse(app(\App\Services\Trip\SpendGuard::class)->canSpend());
    }

    public function test_yesterdays_spend_does_not_count_against_today(): void
    {
        AiConfig::set('daily_spend_ceiling', 5);

        $log = AiLog::create([
            'user_id'  => $this->traveller->id,
            'type'     => 'plan',
            'status'   => 'success',
            'cost_inr' => 99.0,
        ]);
        $log->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertTrue(app(\App\Services\Trip\SpendGuard::class)->canSpend());
    }
}
