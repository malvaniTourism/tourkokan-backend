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

        $live = $this->notYetRolledUp($productIds, $from, $to);

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
                'total'  => $views = (int) $totals->views + $live['views'],
                'unique' => (int) $totals->unique_views + $live['unique_views'],
            ],
            'leads' => [
                'total'      => $leads = (int) $totals->leads + $live['leads'],
                'call'       => (int) $totals->leads_call + $live['leads_call'],
                'whatsapp'   => (int) $totals->leads_whatsapp + $live['leads_whatsapp'],
                'directions' => (int) $totals->leads_directions + $live['leads_directions'],
                'enquiry'    => (int) $totals->leads_enquiry + $live['leads_enquiry'],
            ],
            // the number a vendor should judge the platform by
            'conversion_rate' => $views > 0 ? round(($leads / $views) * 100, 2) : 0,
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
                   'leads_call', 'leads_whatsapp', 'leads_directions', 'leads_enquiry'])
            ->map(fn($row) => array_merge($row->toArray(), ['date' => $row->date->toDateString()]));

        // Same reason as myUsageStats: without this, today's activity is missing until the
        // nightly rollup, and the chart's last point is always empty.
        $series = $series
            ->concat($this->unrolledSeries($product->id, $from, $to))
            ->sortBy('date')
            ->values();

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
     * Per-day rows for one product covering days the rollup has not reached, shaped like
     * the stored rows so the client cannot tell them apart.
     */
    private function unrolledSeries(int $productId, string $from, string $to)
    {
        $views = DB::table('product_view_events')
            ->where('product_id', $productId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('product_daily_stats')
                ->whereColumn('product_daily_stats.product_id', 'product_view_events.product_id')
                ->whereRaw('product_daily_stats.date = DATE(product_view_events.created_at)'))
            ->selectRaw('DATE(created_at) d, COUNT(*) views, COUNT(DISTINCT session_hash) unique_views')
            ->groupBy('d')->get()->keyBy('d');

        $leads = DB::table('product_leads')
            ->where('product_id', $productId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from('product_daily_stats')
                ->whereColumn('product_daily_stats.product_id', 'product_leads.product_id')
                ->whereRaw('product_daily_stats.date = DATE(product_leads.created_at)'))
            ->selectRaw(
                "DATE(created_at) d, COUNT(*) leads, SUM(lead_type='call') leads_call,"
                . " SUM(lead_type='whatsapp') leads_whatsapp,"
                . " SUM(lead_type='directions') leads_directions,"
                . " SUM(lead_type='enquiry') leads_enquiry"
            )
            ->groupBy('d')->get()->keyBy('d');

        return collect($views->keys())->merge($leads->keys())->unique()->map(fn($d) => [
            'date'             => $d,
            'views'            => (int) ($views[$d]->views ?? 0),
            'unique_views'     => (int) ($views[$d]->unique_views ?? 0),
            'leads'            => (int) ($leads[$d]->leads ?? 0),
            'leads_call'       => (int) ($leads[$d]->leads_call ?? 0),
            'leads_whatsapp'   => (int) ($leads[$d]->leads_whatsapp ?? 0),
            'leads_directions' => (int) ($leads[$d]->leads_directions ?? 0),
            'leads_enquiry'    => (int) ($leads[$d]->leads_enquiry ?? 0),
        ]);
    }

    /**
     * Counts for days the rollup has not covered yet — today, and any night the job missed.
     *
     * Without this a vendor's dashboard reads zero until the next 00:45 run, so the first
     * enquiry of the day shows up in `myLeads` while the summary above it still says none.
     * On launch day every vendor would see zeros no matter what happened.
     *
     * Matched per (product, date) rather than by date alone, so a day that was rolled up
     * for one product and not another is still counted exactly once.
     *
     * @return array<string, int>
     */
    private function notYetRolledUp($productIds, string $from, string $to): array
    {
        $unrolled = fn(string $table) => DB::table($table)
            ->whereIn('product_id', $productIds)
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereNotExists(fn($q) => $q->select(DB::raw(1))
                ->from('product_daily_stats')
                ->whereColumn('product_daily_stats.product_id', "{$table}.product_id")
                ->whereRaw("product_daily_stats.date = DATE({$table}.created_at)"));

        $views = (clone $unrolled('product_view_events'))
            ->selectRaw('COUNT(*) v, COUNT(DISTINCT session_hash) u')->first();

        $leads = (clone $unrolled('product_leads'))
            ->selectRaw(
                "COUNT(*) l, SUM(lead_type = 'call') c, SUM(lead_type = 'whatsapp') w,"
                . " SUM(lead_type = 'directions') d, SUM(lead_type = 'enquiry') e"
            )->first();

        return [
            'views'            => (int) ($views->v ?? 0),
            'unique_views'     => (int) ($views->u ?? 0),
            'leads'            => (int) ($leads->l ?? 0),
            'leads_call'       => (int) ($leads->c ?? 0),
            'leads_whatsapp'   => (int) ($leads->w ?? 0),
            'leads_directions' => (int) ($leads->d ?? 0),
            'leads_enquiry'    => (int) ($leads->e ?? 0),
        ];
    }

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
