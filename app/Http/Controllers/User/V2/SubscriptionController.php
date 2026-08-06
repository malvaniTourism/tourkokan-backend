<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\Request;

/**
 * What a vendor can see about their own plan.
 *
 * Listing is free for the launch year, so this screen is mostly reassurance — but the usage
 * figures are the same ones enforcement reads, so a vendor is never surprised by a limit
 * they could not see coming. See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class SubscriptionController extends BaseController
{
    public function __construct(private PlanService $plans)
    {
        $this->middleware('auth:api');
    }

    /**
     * POST /api/v2/mySubscription
     */
    public function mySubscription(Request $request)
    {
        $user         = auth()->user();
        $subscription = $this->plans->subscriptionFor($user);
        $plan         = $this->plans->planFor($user);

        return $this->sendResponse([
            'plan' => $plan ? [
                'code'           => $plan->code,
                'name'           => $plan->name,
                'mr_name'        => $plan->mr_name,
                'description'    => $plan->description,
                'price'          => $plan->price,
                'currency'       => $plan->currency,
                'billing_period' => $plan->billing_period,
            ] : null,
            'subscription' => $subscription ? [
                'starts_at'      => $subscription->starts_at,
                'ends_at'        => $subscription->ends_at,
                'days_remaining' => $subscription->days_remaining,
                'status'         => $subscription->status,
                'auto_renew'     => $subscription->auto_renew,
            ] : null,
            'usage' => $this->plans->usageSummary($user),
        ], 'Subscription fetched.');
    }

    /**
     * POST /api/v2/listPlans — the upgrade screen.
     *
     * Inactive tiers are hidden, so seeding a plan does not advertise it before pricing is
     * settled.
     */
    public function listPlans(Request $request)
    {
        $plans = Plan::active()
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'mr_name', 'description', 'price', 'currency', 'billing_period', 'limits']);

        return $this->sendResponse($plans, 'Plans fetched.');
    }
}
