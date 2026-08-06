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
     * A vendor may only attach products to a site they own **and** that an admin has
     * approved — otherwise a pending submission becomes a way to publish unreviewed
     * listings.
     */
    public function createOn(User $user, Site $site): bool
    {
        return $site->user_id === $user->id
            && $site->submission_status === 'approved'
            && (bool) $site->status;
    }

    private function owns(User $user, Product $product): bool
    {
        return $product->site?->user_id === $user->id;
    }
}
