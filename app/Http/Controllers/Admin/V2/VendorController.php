<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Product;
use App\Models\ProductLead;
use App\Models\Site;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Vendor administration.
 *
 * There is no `vendors` table — a vendor is a user holding the `vendor` role, and their
 * businesses are the sites they own (see docs/VENDOR_PRODUCTS_DESIGN.md §2.1). That keeps
 * ownership unambiguous, but it means "list all vendors" is a query nothing else performs,
 * so it lives here.
 */
class VendorController extends BaseController
{
    public function __construct(private PlanService $plans)
    {
    }

    /**
     * POST /admin/v2/listVendors
     *
     * Filters: search, plan_code, has_pending_products, has_no_sites
     */
    public function listVendors(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'               => 'nullable|string|max:100',
            'plan_code'            => 'nullable|string|exists:plans,code',
            'has_pending_products' => 'nullable|boolean',
            'has_no_sites'         => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $vendors = User::query()
            ->whereHas('roles', fn($q) => $q->where('code', 'vendor'))
            ->withCount([
                'sites',
                'sites as approved_sites_count' => fn($q) => $q->where('submission_status', 'approved'),
                'sites as pending_sites_count'  => fn($q) => $q->where('submission_status', 'pending'),
            ])
            ->with(['sites' => fn($q) => $q->select('id', 'user_id', 'name', 'logo', 'is_primary', 'submission_status')])
            ->when($request->boolean('has_no_sites'), fn($q) => $q->whereDoesntHave('sites'))
            ->when($request->filled('plan_code'), fn($q) => $q->whereHas(
                'vendorSubscriptions',
                fn($s) => $s->current()->whereHas('plan', fn($p) => $p->where('code', $request->plan_code))
            ))
            ->when($request->boolean('has_pending_products'), fn($q) => $q->whereHas(
                'sites',
                fn($s) => $s->whereHas('products', fn($p) => $p->where('status', 'pending'))
            ))
            ->latest('id')
            ->paginateSafe();

        // Product counts and plan are per-vendor lookups; doing them once for the page
        // keeps this off the N+1 path.
        $userIds     = collect($vendors->items())->pluck('id');
        $productStats = $this->productStatsFor($userIds);
        $plans        = $this->plansFor($userIds);

        $vendors->getCollection()->transform(function (User $vendor) use ($productStats, $plans) {
            $primary = $vendor->sites->firstWhere('is_primary', true) ?? $vendor->sites->first();
            $stats   = $productStats[$vendor->id] ?? [];

            return [
                'id'            => $vendor->id,
                'name'          => $vendor->name,
                'email'         => $vendor->email,
                'mobile'        => $vendor->mobile,
                'joined_at'     => $vendor->created_at,
                'business_name' => $primary?->name,
                'logo'          => $primary?->logo,
                'sites'         => [
                    'total'    => $vendor->sites_count,
                    'approved' => $vendor->approved_sites_count,
                    'pending'  => $vendor->pending_sites_count,
                ],
                'products'      => [
                    'total'    => array_sum($stats),
                    'approved' => $stats['approved'] ?? 0,
                    'pending'  => $stats['pending'] ?? 0,
                    'draft'    => $stats['draft'] ?? 0,
                    'rejected' => $stats['rejected'] ?? 0,
                    'paused'   => $stats['paused'] ?? 0,
                ],
                'plan'          => $plans[$vendor->id] ?? null,
            ];
        });

        return $this->sendResponse($vendors, 'Vendors retrieved successfully...!');
    }

    /**
     * POST /admin/v2/getVendor
     *
     * Everything about one vendor: their businesses with categories, their catalog by
     * status, plan and quota usage, and engagement totals.
     */
    public function getVendor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $vendor = User::with('roles:id,name,code')->find($request->id);

        if (!$vendor->hasRole('vendor')) {
            return $this->sendError('That user is not a vendor.', '', 422);
        }

        $sites = Site::ownedBy($vendor->id)
            ->with('categories:id,name,mr_name,code,parent_id')
            ->withCount([
                'products',
                'products as approved_products_count' => fn($q) => $q->where('status', 'approved'),
                'products as pending_products_count'  => fn($q) => $q->where('status', 'pending'),
            ])
            ->orderByDesc('is_primary')
            ->get([
                'id', 'name', 'mr_name', 'logo', 'image', 'is_primary', 'parent_id',
                'status', 'submission_status', 'rejection_reason',
                'latitude', 'longitude', 'pin_code', 'created_at',
            ]);

        $products = Product::ownedBy($vendor->id)
            ->with(['productCategory:id,name,code,booking_type', 'site:id,name', 'defaultVariant', 'cover'])
            ->latest()
            ->limit(50)
            ->get();

        $productIds = Product::ownedBy($vendor->id)->pluck('id');

        return $this->sendResponse([
            'vendor' => [
                'id'        => $vendor->id,
                'name'      => $vendor->name,
                'email'     => $vendor->email,
                'mobile'    => $vendor->mobile,
                'roles'     => $vendor->roles->pluck('code'),
                'joined_at' => $vendor->created_at,
            ],
            'sites'    => $sites,
            'products' => [
                'by_status' => $this->productStatsFor(collect([$vendor->id]))[$vendor->id] ?? [],
                'recent'    => $products,
            ],
            'plan'         => $this->plans->planFor($vendor)?->only(['code', 'name', 'price', 'limits']),
            'subscription' => $this->plans->subscriptionFor($vendor)?->only(['starts_at', 'ends_at', 'status']),
            'usage'        => $this->plans->usageSummary($vendor),
            'engagement'   => [
                'views' => (int) Product::whereIn('id', $productIds)->sum('views_count'),
                'leads' => (int) Product::whereIn('id', $productIds)->sum('leads_count'),
                'recent_leads' => ProductLead::whereIn('product_id', $productIds)
                    ->with('product:id,name')
                    ->latest()
                    ->limit(10)
                    ->get(),
            ],
        ], 'Vendor retrieved successfully...!');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Product counts per vendor keyed by status, in one query rather than per row.
     *
     * @return array<int, array<string, int>>
     */
    private function productStatsFor($userIds): array
    {
        return DB::table('products')
            ->join('sites', 'sites.id', '=', 'products.site_id')
            ->whereIn('sites.user_id', $userIds)
            ->whereNull('products.deleted_at')
            ->selectRaw('sites.user_id, products.status, COUNT(*) c')
            ->groupBy('sites.user_id', 'products.status')
            ->get()
            ->groupBy('user_id')
            ->map(fn($rows) => $rows->pluck('c', 'status')->map(fn($v) => (int) $v)->all())
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plansFor($userIds): array
    {
        return \App\Models\VendorSubscription::current()
            ->whereIn('user_id', $userIds)
            ->with('plan:id,code,name')
            ->get()
            ->keyBy('user_id')
            ->map(fn($s) => [
                'code'    => $s->plan?->code,
                'name'    => $s->plan?->name,
                'ends_at' => $s->ends_at,
            ])
            ->all();
    }
}
