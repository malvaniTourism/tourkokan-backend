<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\VendorSubscription;
use App\Services\PlanService;
use Tests\ApiTestCase;

/**
 * A subscription's length is derived from its plan's billing_period, not hard-coded at
 * enrolment. Setting a plan to "quarterly" in admin is what makes it last three months;
 * a "free" billing period means it never expires. An explicit months value still overrides,
 * for fixed-length cases like the launch promo or a manual admin grant.
 *
 * Regression context: a quarterly free plan showed ~305 days left (a hard-coded 12 months),
 * and changing a vendor's plan with no months silently produced a never-expiring row.
 */
class SubscriptionDurationTest extends ApiTestCase
{
    private User $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->vendor = $this->userWithRole('vendor');
    }

    private function activeSubscription(User $user): ?VendorSubscription
    {
        return VendorSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('starts_at')
            ->first();
    }

    public function test_free_enrolment_length_follows_the_plans_billing_period(): void
    {
        Plan::where('code', 'free')->update(['billing_period' => 'quarterly']);

        $subscription = app(PlanService::class)->enrolOnFree($this->vendor);

        $this->assertNotNull($subscription->ends_at, 'a quarterly plan is dated, not indefinite');
        $this->assertEqualsWithDelta(3, now()->diffInMonths($subscription->ends_at), 1);
    }

    public function test_a_free_billing_period_never_expires(): void
    {
        Plan::where('code', 'free')->update(['billing_period' => 'free']);

        $subscription = app(PlanService::class)->enrolOnFree($this->vendor);

        $this->assertNull($subscription->ends_at, "a 'free' billing period carries no end date");
    }

    public function test_an_explicit_override_beats_the_billing_period(): void
    {
        Plan::where('code', 'free')->update(['billing_period' => 'quarterly']);

        $subscription = app(PlanService::class)->enrolOnFree($this->vendor, 1);

        $this->assertEqualsWithDelta(1, now()->diffInMonths($subscription->ends_at), 1);
    }

    public function test_assign_plan_without_months_uses_the_plans_billing_period(): void
    {
        $admin  = $this->userWithRole('admin');
        $growth = Plan::where('code', 'growth')->first();
        $growth->update(['billing_period' => 'yearly', 'is_active' => true]);

        app(PlanService::class)->enrolOnFree($this->vendor);

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/assignPlan', [
                'user_id' => $this->vendor->id,
                'plan_id' => $growth->id,
            ])
        );

        $subscription = $this->activeSubscription($this->vendor->fresh());
        $this->assertSame('growth', $subscription->plan->code);
        $this->assertNotNull($subscription->ends_at);
        $this->assertEqualsWithDelta(12, now()->diffInMonths($subscription->ends_at), 1);
    }

    public function test_assign_plan_still_honours_an_explicit_months(): void
    {
        $admin  = $this->userWithRole('admin');
        $growth = Plan::where('code', 'growth')->first();
        $growth->update(['billing_period' => 'yearly', 'is_active' => true]);

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/assignPlan', [
                'user_id' => $this->vendor->id,
                'plan_id' => $growth->id,
                'months'  => 2,
            ])
        );

        $subscription = $this->activeSubscription($this->vendor->fresh());
        $this->assertEqualsWithDelta(2, now()->diffInMonths($subscription->ends_at), 1);
    }
}
