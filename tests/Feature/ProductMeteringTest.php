<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductDailyStat;
use App\Models\ProductLead;
use App\Models\ProductVariant;
use App\Models\ProductViewEvent;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * The metering pipeline: raw capture → nightly rollup → prune, and what a vendor sees.
 *
 * The rollup feeds usage-based billing later, so double-counting is not a cosmetic bug —
 * it inflates what a vendor is charged. Idempotency is the test that matters most here.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class ProductMeteringTest extends ApiTestCase
{
    private User $vendor;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');

        Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms', 'icon' => '', 'status' => true]);
        $pc = ProductCategory::create(['name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night']);

        $site = Site::create([
            'name' => 'Sagar Resort', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);

        $this->product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $pc->id,
            'name' => 'Deluxe Room', 'slug' => 'deluxe-room',
            'base_price' => 2400, 'status' => 'approved',
        ]);

        ProductVariant::create([
            'product_id' => $this->product->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);
    }

    /**
     * `created_at` is not mass assignable — correct for production, where an event always
     * happens now — so backdating a fixture has to go through forceFill.
     */
    private function recordView(string $day, string $session = 'a'): void
    {
        ProductViewEvent::create([
            'product_id'   => $this->product->id,
            'session_hash' => $session,
        ])->forceFill(['created_at' => $day . ' 10:00:00'])->saveQuietly();
    }

    private function recordLead(string $day, string $type = 'call'): void
    {
        ProductLead::create([
            'product_id' => $this->product->id,
            'lead_type'  => $type,
        ])->forceFill([
            'created_at' => $day . ' 11:00:00',
            'updated_at' => $day . ' 11:00:00',
        ])->saveQuietly();
    }

    // ── Rollup ───────────────────────────────────────────────────────────────────

    public function test_the_rollup_aggregates_views_and_leads_for_a_day(): void
    {
        $day = now()->subDay()->toDateString();

        $this->recordView($day, 'session-1');
        $this->recordView($day, 'session-1');   // same visitor returning
        $this->recordView($day, 'session-2');
        $this->recordLead($day, 'call');
        $this->recordLead($day, 'whatsapp');

        $this->artisan('products:rollup-stats')->assertSuccessful();

        $stat = ProductDailyStat::where('product_id', $this->product->id)->firstOrFail();

        $this->assertSame(3, $stat->views);
        $this->assertSame(2, $stat->unique_views, 'one visitor reopening a listing is not two people');
        $this->assertSame(2, $stat->leads);
        $this->assertSame(1, $stat->leads_call);
        $this->assertSame(1, $stat->leads_whatsapp);
        $this->assertSame(0, $stat->leads_directions);
    }

    public function test_re_running_the_rollup_overwrites_rather_than_doubles(): void
    {
        // Billing is built on these numbers — a replayed night must not inflate them.
        $day = now()->subDay()->toDateString();
        $this->recordView($day);
        $this->recordLead($day);

        $this->artisan('products:rollup-stats')->assertSuccessful();
        $this->artisan('products:rollup-stats')->assertSuccessful();
        $this->artisan('products:rollup-stats')->assertSuccessful();

        $this->assertSame(1, ProductDailyStat::count(), 'one row per product per day');
        $this->assertSame(1, ProductDailyStat::first()->views);
        $this->assertSame(1, ProductDailyStat::first()->leads);
    }

    public function test_a_specific_day_can_be_replayed(): void
    {
        $old = now()->subDays(10)->toDateString();
        $this->recordView($old);

        $this->artisan('products:rollup-stats')->assertSuccessful();
        $this->assertSame(0, ProductDailyStat::count(), 'the default run covers yesterday only');

        $this->artisan("products:rollup-stats --date={$old}")->assertSuccessful();
        $this->assertSame(1, ProductDailyStat::where('date', $old)->count());
    }

    public function test_a_backlog_of_days_can_be_rolled_up_in_one_run(): void
    {
        foreach ([1, 2, 3] as $back) {
            $this->recordView(now()->subDays($back)->toDateString());
        }

        $this->artisan('products:rollup-stats --days=3')->assertSuccessful();

        $this->assertSame(3, ProductDailyStat::count());
    }

    public function test_a_day_with_no_activity_produces_no_row(): void
    {
        $this->artisan('products:rollup-stats')->assertSuccessful();

        $this->assertSame(0, ProductDailyStat::count());
    }

    // ── Prune ────────────────────────────────────────────────────────────────────

    public function test_pruning_removes_rolled_up_raw_events_but_keeps_the_aggregate(): void
    {
        $old = now()->subDays(120)->toDateString();
        $this->recordView($old);
        $this->recordLead($old);

        $this->artisan("products:rollup-stats --date={$old}")->assertSuccessful();
        $this->artisan('products:prune-view-events')->assertSuccessful();

        $this->assertSame(0, ProductViewEvent::count(), 'raw views discarded');
        $this->assertSame(1, ProductDailyStat::count(), 'the aggregate survives');
        $this->assertSame(1, ProductLead::count(), 'leads are the billable record and are never pruned');
    }

    public function test_pruning_refuses_a_day_that_was_never_rolled_up(): void
    {
        // A failed nightly job must not also cost you the underlying data.
        $this->recordView(now()->subDays(120)->toDateString());

        $this->artisan('products:prune-view-events')->assertFailed();

        $this->assertSame(1, ProductViewEvent::count(), 'nothing deleted');
    }

    public function test_force_prunes_even_without_a_rollup(): void
    {
        $this->recordView(now()->subDays(120)->toDateString());

        $this->artisan('products:prune-view-events --force')->assertSuccessful();

        $this->assertSame(0, ProductViewEvent::count());
    }

    public function test_recent_events_are_left_alone(): void
    {
        $this->recordView(now()->subDays(5)->toDateString());

        $this->artisan('products:prune-view-events')->assertSuccessful();

        $this->assertSame(1, ProductViewEvent::count());
    }

    // ── Vendor-facing analytics ──────────────────────────────────────────────────

    public function test_usage_stats_summarise_the_account(): void
    {
        $day = now()->subDay()->toDateString();
        $this->recordView($day, 's1');
        $this->recordView($day, 's2');
        $this->recordLead($day, 'call');
        $this->artisan('products:rollup-stats')->assertSuccessful();

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myUsageStats')
        );

        $this->assertSame(2, $response->json('data.views.total'));
        $this->assertSame(1, $response->json('data.leads.total'));
        $this->assertSame(1, $response->json('data.leads.call'));
        $this->assertSame(1, $response->json('data.listings.live'));
        $this->assertEquals(50, $response->json('data.conversion_rate'), '1 lead from 2 views');
    }

    public function test_usage_stats_are_scoped_to_the_calling_vendor(): void
    {
        $day = now()->subDay()->toDateString();
        $this->recordView($day);
        $this->artisan('products:rollup-stats')->assertSuccessful();

        $stranger = $this->userWithRole('vendor');

        $response = $this->assertApiSuccess(
            $this->actingAs($stranger, 'api')->postJson('/api/v2/myUsageStats')
        );

        $this->assertSame(0, $response->json('data.views.total'));
        $this->assertSame(0, $response->json('data.listings.total'));
    }

    public function test_product_analytics_returns_a_daily_series(): void
    {
        foreach ([1, 2] as $back) {
            $this->recordView(now()->subDays($back)->toDateString());
        }
        $this->artisan('products:rollup-stats --days=2')->assertSuccessful();

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/productAnalytics', ['id' => $this->product->id])
        );

        $this->assertCount(2, $response->json('data.daily'));
        $this->assertSame(2, $response->json('data.totals.views'));
    }

    public function test_a_vendor_cannot_read_another_vendors_analytics(): void
    {
        $stranger = $this->userWithRole('vendor');

        $this->assertApiFailure(
            $this->actingAs($stranger, 'api')
                ->postJson('/api/v2/productAnalytics', ['id' => $this->product->id]),
            404
        );
    }

    public function test_my_leads_lists_the_actual_enquiries(): void
    {
        $day = now()->subDay()->toDateString();
        $this->recordLead($day, 'call');
        $this->recordLead($day, 'whatsapp');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myLeads')
        );

        $this->assertCount(2, $response->json('data.data'));

        $filtered = $this->actingAs($this->vendor, 'api')
            ->postJson('/api/v2/myLeads', ['lead_type' => 'call']);

        $this->assertCount(1, $filtered->json('data.data'));
    }

    public function test_my_leads_never_leaks_another_vendors_enquiries(): void
    {
        $this->recordLead(now()->subDay()->toDateString());

        $stranger = $this->userWithRole('vendor');

        $response = $this->actingAs($stranger, 'api')->postJson('/api/v2/myLeads');

        $this->assertSame([], $response->json('data.data'));
    }

    public function test_an_excessive_date_range_is_rejected(): void
    {
        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myUsageStats', [
                'from' => now()->subYears(3)->toDateString(),
                'to'   => now()->toDateString(),
            ]),
            422
        );
    }

    public function test_todays_activity_appears_before_the_rollup_has_run(): void
    {
        // Otherwise a vendor's first enquiry of the day shows in myLeads while the summary
        // above it still reads zero — and on launch day everyone sees zeros regardless.
        $this->recordView(now()->toDateString(), 's1');
        $this->recordLead(now()->toDateString(), 'whatsapp');

        $this->assertSame(0, ProductDailyStat::count(), 'nothing rolled up yet');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myUsageStats')
        );

        $this->assertSame(1, $response->json('data.views.total'));
        $this->assertSame(1, $response->json('data.leads.total'));
        $this->assertSame(1, $response->json('data.leads.whatsapp'));
    }

    public function test_rolled_up_days_are_not_double_counted(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->recordView($yesterday, 's1');
        $this->recordLead($yesterday, 'call');
        $this->recordView(now()->toDateString(), 's2');

        $this->artisan('products:rollup-stats')->assertSuccessful();

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myUsageStats')
        );

        $this->assertSame(2, $response->json('data.views.total'), 'one rolled up, one live');
        $this->assertSame(1, $response->json('data.leads.total'));
    }

    public function test_the_daily_series_includes_today(): void
    {
        $this->recordView(now()->subDay()->toDateString());
        $this->recordView(now()->toDateString());
        $this->artisan('products:rollup-stats')->assertSuccessful();

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/productAnalytics', ['id' => $this->product->id])
        );

        $dates = collect($response->json('data.daily'))->pluck('date');

        $this->assertCount(2, $dates, 'yesterday from the rollup, today live');
        $this->assertContains(now()->toDateString(), $dates);
        $this->assertSame(2, $response->json('data.totals.views'));
    }

    public function test_analytics_are_closed_to_non_vendors(): void
    {
        $plain = $this->userWithRole('user');

        $this->actingAs($plain, 'api')->postJson('/api/v2/myUsageStats')->assertStatus(403);
    }
}
