<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\Site;
use App\Models\User;

/**
 * Ownership is derived through the site — `$product->site->user_id` — because that is the
 * single source of truth for who owns what. See docs/VENDOR_PRODUCTS_DESIGN.md §2.3.
 *
 * When multi-user vendor accounts arrive, only `owns()` changes: the comparison becomes
 * "site->user_id is one of my vendor's user ids". That is cheap precisely because
 * ownership is one column rather than two that can disagree.
 */
class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    /**
     * A vendor may build their catalog while the business itself is still under review.
     *
     * Waiting for site approval before allowing any product meant a vendor onboarded, then
     * sat idle, then had to come back — two round trips before they saw any value. Instead
     * both queues fill in parallel: the site and its products are all `pending`, and an
     * admin reviews the business once and its listings alongside it.
     *
     * Nothing becomes publicly visible early. Approval is still per-product, and
     * Admin\V2\ProductController::approveProduct refuses to approve a listing whose site is
     * not yet live, so the site is necessarily approved first.
     *
     * A rejected site is excluded: fix the business listing before adding to it.
     */
    public function createOn(User $user, Site $site): bool
    {
        return $site->user_id === $user->id
            && in_array($site->submission_status, ['pending', 'approved'], true);
    }

    private function owns(User $user, Product $product): bool
    {
        return $product->site?->user_id === $user->id;
    }
}
