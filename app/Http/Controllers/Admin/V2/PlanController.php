<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Plan;
use App\Models\User;
use App\Models\VendorSubscription;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Plan administration.
 *
 * Going paid is meant to be a data change: activate a tier here, move subscriptions onto
 * it. Nothing in the enforcement path reads a price.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class PlanController extends BaseController
{
    public function __construct(private PlanService $plans)
    {
    }

    public function listPlans(Request $request)
    {
        $plans = Plan::withCount(['subscriptions' => fn($q) => $q->where('status', 'active')])
            ->orderBy('sort_order')
            ->get();

        return $this->sendResponse($plans, 'Plans retrieved successfully...!');
    }

    public function addPlan(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules() + [
            'code' => 'required|string|max:40|regex:/^[a-z][a-z0-9_]*$/|unique:plans,code',
            'name' => 'required|string|max:60',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        if ($errors = $this->invalidLimitKeys($request)) {
            return $this->sendError(['limits' => $errors], '', 422);
        }

        $plan = Plan::create($request->only([
            'code', 'name', 'mr_name', 'description', 'price', 'currency',
            'billing_period', 'limits', 'is_active', 'sort_order',
        ]));

        return $this->sendResponse($plan, 'Plan created successfully...!');
    }

    public function updatePlan(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules() + [
            'id'   => 'required|numeric|exists:plans,id',
            'name' => 'sometimes|string|max:60',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        if ($errors = $this->invalidLimitKeys($request)) {
            return $this->sendError(['limits' => $errors], '', 422);
        }

        $plan = Plan::find($request->id);

        $plan->update($request->only([
            'name', 'mr_name', 'description', 'price', 'currency',
            'billing_period', 'limits', 'is_active', 'sort_order',
        ]));

        return $this->sendResponse($plan->fresh(), 'Plan updated successfully...!');
    }

    public function listSubscriptions(Request $request)
    {
        $subscriptions = VendorSubscription::with(['user:id,name,email', 'plan:id,code,name'])
            ->when($request->filled('plan_id'), fn($q) => $q->where('plan_id', $request->plan_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->boolean('expiring_soon'), fn($q) => $q
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [now(), now()->addDays(30)]))
            ->latest()
            ->paginateSafe();

        return $this->sendResponse($subscriptions, 'Subscriptions retrieved successfully...!');
    }

    /**
     * Move a vendor onto a plan. This is how going paid happens — no code change.
     */
    public function assignPlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric|exists:users,id',
            'plan_id' => 'required|numeric|exists:plans,id',
            'months'  => 'nullable|integer|min:1|max:120',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        // Close the old subscription rather than leaving two active — `current()` takes the
        // latest, but two live rows would make the vendor's quota depend on ordering.
        VendorSubscription::where('user_id', $request->user_id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled']);

        $subscription = VendorSubscription::create([
            'user_id'    => $request->user_id,
            'plan_id'    => $request->plan_id,
            'starts_at'  => now(),
            'ends_at'    => $request->filled('months') ? now()->addMonths((int) $request->months) : null,
            'status'     => 'active',
            'price_paid' => Plan::find($request->plan_id)->price,
        ]);

        return $this->sendResponse(
            $subscription->load('plan:id,code,name'),
            'Plan assigned successfully...!'
        );
    }

    /**
     * What one vendor is using against their quota — the answer to "why can't they add more?"
     */
    public function vendorUsageReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $user = User::find($request->user_id);

        return $this->sendResponse([
            'user'         => $user->only(['id', 'name', 'email']),
            'plan'         => $this->plans->planFor($user)?->only(['code', 'name', 'limits']),
            'subscription' => $this->plans->subscriptionFor($user)?->only(['starts_at', 'ends_at', 'status']),
            'usage'        => $this->plans->usageSummary($user),
        ], 'Vendor usage retrieved successfully...!');
    }

    private function rules(): array
    {
        return [
            'mr_name'        => 'nullable|string|max:60',
            'description'    => 'nullable|string|max:500',
            'price'          => 'nullable|numeric|min:0',
            'currency'       => 'nullable|string|size:3',
            'billing_period' => 'nullable|string|in:free,monthly,quarterly,yearly',
            'limits'         => 'nullable|array',
            'is_active'      => 'nullable|boolean',
            'sort_order'     => 'nullable|integer|min:0',
        ];
    }

    /**
     * A typo in a limit key would silently stop being enforced, so unknown keys are refused.
     */
    private function invalidLimitKeys(Request $request): array
    {
        $unknown = array_diff(array_keys($request->input('limits') ?? []), array_keys(PlanService::LIMITS));

        return $unknown
            ? ['Unknown limit key(s): ' . implode(', ', $unknown)
               . '. Allowed: ' . implode(', ', array_keys(PlanService::LIMITS)) . '.']
            : [];
    }
}
