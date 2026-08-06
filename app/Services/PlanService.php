<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use App\Models\VendorSubscription;

/**
 * Resolves a vendor's plan and answers whether they have room for one more of something.
 *
 * Listing is free for the first year, so in practice these checks pass — but the
 * enforcement point has to exist before vendors accumulate listings, or enabling limits
 * later retroactively puts existing accounts over quota.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class PlanService
{
    /**
     * Every quota key, mapped to how it is counted.
     *
     * `scope` decides what the count is relative to:
     *   account  everything the vendor owns
     *   product  the single product named in the request
     */
    public const LIMITS = [
        'max_sites'              => 'account',
        'max_products'           => 'account',
        'max_images_per_product' => 'product',
        'featured_slots'         => 'account',
    ];

    /**
     * The vendor's current plan, falling back to `free`.
     *
     * The fallback is what lets a vendor who predates the plans table — or whose
     * subscription lapsed — keep working on free terms instead of being locked out. A
     * missing plan should degrade, never deny.
     */
    public function planFor(User $user): ?Plan
    {
        $subscription = $this->subscriptionFor($user);

        return $subscription?->plan ?? Plan::where('code', Plan::FREE)->active()->first();
    }

    public function subscriptionFor(User $user): ?VendorSubscription
    {
        return VendorSubscription::current()
            ->where('user_id', $user->id)
            ->with('plan')
            ->latest('starts_at')
            ->first();
    }

    /**
     * Enrol a vendor on the free plan.
     *
     * Idempotent — calling it for someone who already has a live subscription returns that
     * one rather than stacking a second.
     */
    public function enrolOnFree(User $user, int $months = 12): ?VendorSubscription
    {
        if ($existing = $this->subscriptionFor($user)) {
            return $existing;
        }

        $free = Plan::where('code', Plan::FREE)->active()->first();

        if (!$free) {
            return null;
        }

        return VendorSubscription::create([
            'user_id'    => $user->id,
            'plan_id'    => $free->id,
            'starts_at'  => now(),
            // A dated free period means the first renewal decision has a deadline attached
            // rather than drifting indefinitely.
            'ends_at'    => now()->addMonths($months),
            'status'     => 'active',
            'price_paid' => 0,
            'auto_renew' => false,
        ]);
    }

    /**
     * Current usage against a quota.
     *
     * @return array{limit: ?int, used: int, remaining: ?int, exceeded: bool}
     */
    public function usage(User $user, string $key, ?int $productId = null): array
    {
        $plan  = $this->planFor($user);
        $limit = $plan?->limit($key);
        $used  = $this->countFor($user, $key, $productId);

        return [
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'exceeded'  => $limit !== null && $used >= $limit,
        ];
    }

    /**
     * Whether the vendor may create one more.
     */
    public function hasCapacity(User $user, string $key, ?int $productId = null): bool
    {
        return !$this->usage($user, $key, $productId)['exceeded'];
    }

    /**
     * A message a vendor can act on — states the limit and names the plan.
     */
    public function limitMessage(User $user, string $key): string
    {
        $usage = $this->usage($user, $key);
        $plan  = $this->planFor($user);

        $subject = match ($key) {
            'max_sites'              => 'business listings',
            'max_products'           => 'products',
            'max_images_per_product' => 'images on this product',
            'featured_slots'         => 'featured listings',
            default                  => $key,
        };

        return sprintf(
            'Your %s plan allows %d %s and you have %d. Upgrade to add more.',
            $plan?->name ?? 'current',
            $usage['limit'],
            $subject,
            $usage['used']
        );
    }

    /**
     * A full quota picture, for the vendor's subscription screen.
     */
    public function usageSummary(User $user): array
    {
        $summary = [];

        foreach (self::LIMITS as $key => $scope) {
            if ($scope !== 'account') {
                // per-product quotas have no single account-wide figure; the plan's limit
                // is still reported so the app can show it
                $summary[$key] = ['limit' => $this->planFor($user)?->limit($key)];
                continue;
            }

            $summary[$key] = $this->usage($user, $key);
        }

        return $summary;
    }

    private function countFor(User $user, string $key, ?int $productId): int
    {
        return match ($key) {
            'max_sites' => Site::where('user_id', $user->id)
                ->whereIn('submission_status', ['pending', 'approved'])
                ->count(),

            'max_products' => Product::ownedBy($user->id)->count(),

            'featured_slots' => Product::ownedBy($user->id)->where('is_featured', true)->count(),

            'max_images_per_product' => $productId === null ? 0 : Gallery::where('galleryable_type', Product::class)
                ->where('galleryable_id', $productId)
                ->count(),

            default => 0,
        };
    }
}
