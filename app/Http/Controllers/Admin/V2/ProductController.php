<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Product;
use App\Services\VendorNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin moderation of vendor listings.
 *
 * Mirrors the existing site-submission review flow (pendingSites / approveSite /
 * rejectSite). See docs/VENDOR_PRODUCTS_DESIGN.md §7.
 */
class ProductController extends BaseController
{
    public function __construct(private VendorNotifier $notifier)
    {
    }

    /**
     * POST /admin/v2/pendingProducts
     */
    public function pendingProducts(Request $request)
    {
        $products = Product::awaitingReview()
            ->with(['site:id,name,user_id', 'site.user:id,name', 'productCategory:id,name,code', 'defaultVariant', 'cover'])
            ->oldest()          // longest-waiting first
            ->paginateSafe();

        return $this->sendResponse($products, 'Pending products retrieved successfully...!');
    }

    /**
     * POST /admin/v2/listAllProducts
     */
    public function listAllProducts(Request $request)
    {
        $products = Product::query()
            ->with(['site:id,name,user_id', 'productCategory:id,name,code', 'defaultVariant'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('site_id'), fn($q) => $q->where('site_id', $request->site_id))
            ->when($request->filled('product_category_id'), fn($q) => $q->where('product_category_id', $request->product_category_id))
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->latest()
            ->paginateSafe();

        return $this->sendResponse($products, 'Products retrieved successfully...!');
    }

    /**
     * POST /admin/v2/getProductAdmin
     */
    public function getProductAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with([
            'site:id,name,user_id',
            'site.user:id,name',
            'productCategory:id,name,code,booking_type,attribute_schema',
            'variants',
            'gallery',
        ])->find($request->id);

        return $this->sendResponse($product, 'Product retrieved successfully...!');
    }

    /**
     * POST /admin/v2/approveProduct
     */
    public function approveProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if ($product->status !== 'pending') {
            return $this->sendError('Only a product awaiting review can be approved.', '', 422);
        }

        // Approving a listing on a site that has since been unpublished would put it live
        // under a site tourists cannot reach.
        if (!$product->site || $product->site->submission_status !== 'approved' || !$product->site->status) {
            return $this->sendError('The product\'s site is not approved and live.', '', 422);
        }

        $product->update(['status' => 'approved', 'rejection_reason' => null]);
        $this->notifier->productApproved($product);

        return $this->sendResponse($product->fresh(), 'Product approved and is now live.');
    }

    /**
     * POST /admin/v2/rejectProduct
     */
    public function rejectProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'               => 'required|numeric|exists:products,id',
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::find($request->id);

        if ($product->status !== 'pending') {
            return $this->sendError('Only a product awaiting review can be rejected.', '', 422);
        }

        $product->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);
        $this->notifier->productRejected($product, $request->rejection_reason);

        return $this->sendResponse($product->fresh(), 'Product rejected.');
    }

    /**
     * POST /admin/v2/featureProduct
     */
    public function featureProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'          => 'required|numeric|exists:products,id',
            'is_featured' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::find($request->id);

        if ($request->boolean('is_featured') && $product->status !== 'approved') {
            return $this->sendError('Only an approved product can be featured.', '', 422);
        }

        $product->update(['is_featured' => $request->boolean('is_featured')]);

        return $this->sendResponse(
            $product->only(['id', 'is_featured']),
            $product->is_featured ? 'Product featured.' : 'Product un-featured.'
        );
    }
}
