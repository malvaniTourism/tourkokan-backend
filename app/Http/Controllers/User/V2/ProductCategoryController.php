<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\AllowedProductCategory;
use App\Models\ProductCategory;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Vendor-facing taxonomy lookups — the two calls the app makes before showing the
 * Add-Product form.
 *
 *   1. allowedProductCategories(site_id)         → which categories this outlet may list
 *   2. categoryAttributeSchema(product_category)  → the fields to render for one of them
 *
 * Together these are what let a new vertical ship without a Play Store release.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §6 and §8.
 */
class ProductCategoryController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Product categories this site is permitted to list, derived from the site's own
     * categories via the allowed_product_categories whitelist.
     *
     * POST /api/v2/allowedProductCategories  { site_id }
     */
    public function allowedProductCategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_id' => 'required|numeric|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::ownedBy(auth()->id())->approved()->find($request->site_id);

        if (!$site) {
            return $this->sendError('Site not found, not yours, or not yet approved.', '', 404);
        }

        $siteCategoryIds = $site->categories()->pluck('categories.id');

        if ($siteCategoryIds->isEmpty()) {
            return $this->sendResponse([], 'This site has no categories, so no products can be listed yet.');
        }

        $allowed = AllowedProductCategory::query()
            ->whereIn('category_id', $siteCategoryIds)
            ->with(['productCategory' => fn($q) => $q->active()->ordered()])
            ->get()
            ->pluck('productCategory')
            ->filter()
            // a site in several categories can reach the same product category twice
            ->unique('id')
            ->values()
            ->map(fn($pc) => [
                'id'                 => $pc->id,
                'name'               => $pc->name,
                'mr_name'            => $pc->mr_name,
                'code'               => $pc->code,
                'icon'               => $pc->icon,
                'booking_type'       => $pc->booking_type,
                'has_attributes'     => !empty($pc->attribute_schema),
            ]);

        return $this->sendResponse($allowed, 'Allowed product categories fetched.');
    }

    /**
     * The attribute schema the app renders its Add-Product form from.
     *
     * POST /api/v2/categoryAttributeSchema  { product_category_id }
     */
    public function categoryAttributeSchema(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_category_id' => 'required|numeric|exists:product_categories,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $category = ProductCategory::active()->find($request->product_category_id);

        if (!$category) {
            return $this->sendError('Product category not found or inactive.', '', 404);
        }

        return $this->sendResponse([
            'id'               => $category->id,
            'name'             => $category->name,
            'mr_name'          => $category->mr_name,
            'code'             => $category->code,
            'booking_type'     => $category->booking_type,
            'attribute_schema' => $category->attribute_schema ?? (object) [],
        ], 'Attribute schema fetched.');
    }
}
