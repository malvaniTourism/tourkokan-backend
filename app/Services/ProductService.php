<?php

namespace App\Services;

use App\Models\AllowedProductCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Catalog rules shared by the vendor and admin controllers.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §2.5 (whitelist) and §3 (booking-ready pricing).
 */
class ProductService
{
    public function __construct(private ProductAttributeValidator $attributeValidator)
    {
    }

    /**
     * The whitelist row permitting this product category under this site, or null.
     *
     * A site reaches a product category through any of its site categories — this is what
     * stops a site categorised "Hospital" from listing mangoes.
     */
    public function permissionFor(Site $site, int $productCategoryId): ?AllowedProductCategory
    {
        $siteCategoryIds = $site->categories()->pluck('categories.id');

        if ($siteCategoryIds->isEmpty()) {
            return null;
        }

        return AllowedProductCategory::whereIn('category_id', $siteCategoryIds)
            ->where('product_category_id', $productCategoryId)
            // a site in several categories can permit the same product category twice;
            // the most generous quota wins
            ->orderByRaw('max_products IS NULL DESC')
            ->orderByDesc('max_products')
            ->first();
    }

    /**
     * Whether the site has room for another product in this category.
     *
     * `max_products` doubles as a per-category quota; null means unlimited. Soft-deleted
     * products do not count against it.
     */
    public function quotaExceeded(Site $site, AllowedProductCategory $permission, ?int $ignoreProductId = null): bool
    {
        if ($permission->max_products === null) {
            return false;
        }

        $count = Product::where('site_id', $site->id)
            ->where('product_category_id', $permission->product_category_id)
            ->when($ignoreProductId, fn($q) => $q->where('id', '!=', $ignoreProductId))
            ->count();

        return $count >= $permission->max_products;
    }

    /**
     * Validate a product's attributes against its category schema.
     *
     * @return array{0: array, 1: array} [castAttributes, errors]
     */
    public function validateAttributes(ProductCategory $category, array $attributes): array
    {
        return $this->attributeValidator->validate($category, $attributes);
    }

    /**
     * Guarantee the product has exactly one default variant.
     *
     * R1 — the authoritative price lives on a variant, never on `products`. A vendor who
     * enters only a single price still gets a variant, so that when per-date pricing
     * arrives there is somewhere for the override to attach and no price read has to
     * change. See docs/VENDOR_PRODUCTS_DESIGN.md §3.
     */
    public function syncDefaultVariant(Product $product): void
    {
        $default = $product->variants()->where('is_default', true)->first();

        if ($default) {
            // keep the implicit default in step with the product's headline price
            if ($default->name === 'Standard' && $product->base_price !== null) {
                $default->update([
                    'price'      => $product->base_price,
                    'sale_price' => $product->sale_price,
                ]);
            }

            return;
        }

        if ($product->variants()->exists()) {
            // variants exist but none is flagged default — promote the first
            $product->variants()->orderBy('sort_order')->first()->update(['is_default' => true]);

            return;
        }

        $product->variants()->create([
            'name'       => 'Standard',
            'price'      => $product->base_price ?? 0,
            'sale_price' => $product->sale_price,
            'is_default' => true,
            'status'     => true,
        ]);
    }

    /**
     * Slugs are unique per site, so two vendors may both list a "Fish Thali".
     */
    public function uniqueSlug(int $siteId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i    = 1;

        while (Product::withTrashed()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Editing an approved listing sends it back for review, so a vendor cannot get a benign
     * product approved and then rewrite it into something else. Mirrors the site
     * resubmission flow in User\V2\SiteController::updateSubmission.
     */
    public function statusAfterEdit(Product $product): array
    {
        if (in_array($product->status, ['approved', 'rejected'], true)) {
            return ['status' => 'pending', 'rejection_reason' => null];
        }

        return [];
    }
}
