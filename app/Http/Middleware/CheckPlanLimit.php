<?php

namespace App\Http\Middleware;

use App\Services\PlanService;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuses a create request that would take the vendor past their plan's quota.
 *
 * Usage:  ->middleware('plan.limit:max_products')
 *         ->middleware('plan.limit:max_images_per_product')
 *
 * Per-product quotas read the product id from the request, so the middleware stays
 * declarative on the route rather than each controller re-implementing the check.
 *
 * Answers 200 with success:false, matching this API's convention (see
 * docs/VENDOR_PRODUCTS_DESIGN.md §0.4) so existing clients parse it the same way as every
 * other refusal.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class CheckPlanLimit
{
    public function __construct(private PlanService $plans)
    {
    }

    public function handle(Request $request, Closure $next, string $limitKey)
    {
        $user = auth()->user();

        if (!$user) {
            return $next($request);
        }

        // An unknown key must not silently allow everything — that is how a quota quietly
        // stops being enforced after a rename.
        if (!array_key_exists($limitKey, PlanService::LIMITS)) {
            throw new \InvalidArgumentException("Unknown plan limit '{$limitKey}'.");
        }

        $productId = PlanService::LIMITS[$limitKey] === 'product'
            ? (int) $request->input('id')
            : null;

        if (!$this->plans->hasCapacity($user, $limitKey, $productId)) {
            return response()->json([
                'success' => false,
                'message' => $this->plans->limitMessage($user, $limitKey),
                'data'    => ['limit' => $this->plans->usage($user, $limitKey, $productId)],
            ], 200);
        }

        return $next($request);
    }
}
