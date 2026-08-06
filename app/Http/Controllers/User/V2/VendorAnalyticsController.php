<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Product;
use App\Models\ProductDailyStat;
use App\Models\ProductLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * What a vendor sees about their own listings.
 *
 * Reads product_daily_stats rather than the raw event log — the raw log is pruned at 90 days
 * and the aggregate is permanent, so anything shown here stays available for the whole life
 * of the account. See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 *
 * The framing matters commercially: views are shown as value proof, leads as the thing
 * being delivered. When charging starts it is leads that are billed, so the vendor should
 * already be used to reading them as the number that counts.
 */
class VendorAnalyticsController extends BaseController
{
    /** Longest window a vendor may request in one call. */
    private const MAX_RANGE_DAYS = 365;

    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * POST /api/v2/myUsageStats — account-level summary.
     */
    public function myUsageStats(Request $request)
    {
        [$from, $to, $error] = $this->range($request);

        if ($error) {
            return $error;
        }

        $productIds = Product::ownedBy(auth()->id())->pluck('id');

        if ($productIds->isEmpty()) {
            return $this->sendResponse($this->emptySummary($from, $to), 'No listings yet.');
        }

        $totals = ProductDailyStat::whereIn('product_id', $productIds)
            ->whereBetween('date', [$from, $to])
            ->selectRaw(
                'COALESCE(SUM(views),0) views, COALESCE(SUM(unique_views),0) unique_views,'
                . ' COALESCE(SUM(leads),0) leads, COALESCE(SUM(leads_call),0) leads_call,'
                . ' COALESCE(SUM(leads_whatsapp),0) leads_whatsapp,'
                . ' COALESCE(SUM(leads_directions),0) leads_directions,'
                . ' COALESCE(SUM(leads_enquiry),0) leads_enquiry'
            )
            ->first();

        $byStatus = Product::ownedBy(auth()->id())
            ->selectRaw('status, COUNT(*) c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return $this->sendResponse([
            'from' => $from, 'to' => $to,
            'listings' => [
                'total'    => $productIds->count(),
                'live'     => (int) ($byStatus['approved'] ?? 0),
                'draft'    => (int) ($byStatus['draft'] ?? 0),
                'pending'  => (int) ($byStatus['pending'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'paused'   => (int) ($byStatus['paused'] ?? 0),
            ],
            'views' => [
                'total'  => (int) $totals->views,
                'unique' => (int) $totals->unique_views,
            ],
            'leads' => [
                'total'      => (int) $totals->leads,
                'call'       => (int) $totals->leads_call,
                'whatsapp'   => (int) $totals->leads_whatsapp,
                'directions' => (int) $totals->leads_directions,
                'enquiry'    => (int) $totals->leads_enquiry,
            ],
            // the number a vendor should judge the platform by
            'conversion_rate' => $totals->views > 0
                ? round(($totals->leads / $totals->views) * 100, 2)
                : 0,
        ], 'Usage stats fetched.');
    }

    /**
     * POST /api/v2/productAnalytics — one listing, with a daily series for charting.
     */
    public function productAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('view', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        [$from, $to, $error] = $this->range($request);

        if ($error) {
            return $error;
        }

        $series = ProductDailyStat::where('product_id', $product->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get(['date', 'views', 'unique_views', 'leads',
                   'leads_call', 'leads_whatsapp', 'leads_directions', 'leads_enquiry']);

        return $this->sendResponse([
            'product' => $product->only(['id', 'name', 'status', 'views_count', 'leads_count']),
            'from'    => $from,
            'to'      => $to,
            'totals'  => [
                'views'        => (int) $series->sum('views'),
                'unique_views' => (int) $series->sum('unique_views'),
                'leads'        => (int) $series->sum('leads'),
            ],
            // gaps are absent rather than zero-filled — the client charts what it receives
            'daily'   => $series,
        ], 'Product analytics fetched.');
    }

    /**
     * POST /api/v2/myLeads — the actual enquiries, newest first.
     *
     * Counts alone are not actionable; a vendor needs to see who asked and what they said.
     */
    public function myLeads(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'nullable|numeric|exists:products,id',
            'lead_type'  => ['nullable', 'string', 'in:' . implode(',', ProductLead::TYPES)],
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $productIds = Product::ownedBy(auth()->id())->pluck('id');

        $leads = ProductLead::whereIn('product_id', $productIds)
            ->when($request->filled('product_id'),
                fn($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('lead_type'),
                fn($q) => $q->where('lead_type', $request->lead_type))
            ->with(['product:id,name,site_id', 'user:id,name,mobile'])
            ->latest()
            ->paginateSafe();

        return $this->sendResponse($leads, 'Leads fetched.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: string, 2: \Illuminate\Http\JsonResponse|null}
     */
    private function range(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        if ($validator->fails()) {
            return ['', '', $this->sendError($validator->errors(), '', 422)];
        }

        $to   = $request->filled('to') ? now()->parse($request->to) : now();
        $from = $request->filled('from') ? now()->parse($request->from) : $to->copy()->subDays(29);

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            return ['', '', $this->sendError(
                'Date range cannot exceed ' . self::MAX_RANGE_DAYS . ' days.', '', 422
            )];
        }

        return [$from->toDateString(), $to->toDateString(), null];
    }

    private function emptySummary(string $from, string $to): array
    {
        return [
            'from' => $from, 'to' => $to,
            'listings' => ['total' => 0, 'live' => 0, 'draft' => 0, 'pending' => 0, 'rejected' => 0, 'paused' => 0],
            'views'    => ['total' => 0, 'unique' => 0],
            'leads'    => ['total' => 0, 'call' => 0, 'whatsapp' => 0, 'directions' => 0, 'enquiry' => 0],
            'conversion_rate' => 0,
        ];
    }
}
