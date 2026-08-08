<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Product;
use App\Models\ProductLead;
use App\Models\ProductViewEvent;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Public product browsing — what tourists see.
 *
 * Everything here reads through {@see Product::scopeLive}, which requires the listing to be
 * approved, its site to be approved and published, and the availability window to be open.
 * Nothing else in this controller re-checks visibility, so that scope is the single gate.
 *
 * Distinct from User\V2\ProductController, which is the vendor's own write surface.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §7.
 */
class CatalogController extends BaseController
{
    /** Earth radius in kilometres, for the Haversine distance expression. */
    private const EARTH_RADIUS_KM = 6371;

    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * POST /api/v2/listProducts
     *
     * Filters: product_category_id, category_code, site_id, min_price, max_price,
     *          booking_type, search, is_featured, latitude+longitude+radius_km
     * Sort:    latest (default), price_asc, price_desc, popular, nearest
     */
    public function listProducts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_category_id' => 'nullable|numeric|exists:product_categories,id',
            'category_code'       => 'nullable|string|max:60',
            'site_id'             => 'nullable|numeric|exists:sites,id',
            'min_price'           => 'nullable|numeric|min:0',
            // `gte:min_price` unconditionally would reject the commonest filter of all —
            // a max price with no min — because the compared field is absent.
            'max_price'           => ['nullable', 'numeric', 'min:0',
                                      Rule::when($request->filled('min_price'), ['gte:min_price'])],
            'booking_type'        => 'nullable|string|in:none,date_range,slot,quantity',
            'search'              => 'nullable|string|max:100',
            'is_featured'         => 'nullable|boolean',
            'latitude'            => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude'           => 'nullable|numeric|between:-180,180|required_with:latitude',
            'radius_km'           => 'nullable|numeric|min:0.1|max:500',
            'sort'                => 'nullable|string|in:latest,price_asc,price_desc,popular,nearest',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $query = $this->baseQuery($request);

        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        return $this->sendResponse($query->paginateSafe(), 'Products fetched.');
    }

    /**
     * POST /api/v2/getProduct  (public detail — the vendor's own view is getProduct on
     * User\V2\ProductController, which is vendor-gated)
     */
    public function productDetail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'   => 'required_without:slug|nullable|numeric',
            'slug' => 'required_without:id|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::live()
            ->with([
                'productCategory:id,name,mr_name,code,booking_type',
                'site:id,name,mr_name,logo,image,latitude,longitude,pin_code,social_media,domain_name,parent_id',
                'site.site:id,name',
                'variants' => fn($q) => $q->active(),
                'gallery',
            ])
            ->withAvg('rating', 'rate')
            ->withCount(['rating', 'comment'])
            ->when($request->filled('id'), fn($q) => $q->where('id', $request->id))
            ->when($request->filled('slug'), fn($q) => $q->where('slug', $request->slug))
            ->first();

        if (!$product) {
            return $this->sendError('Product not found.', '', 404);
        }

        $payload = $product->toArray();
        $payload['price']        = $product->price;
        $payload['is_favourite'] = $product->favourites()->where('user_id', auth()->id())->exists();

        return $this->sendResponse($payload, 'Product fetched.');
    }

    /**
     * POST /api/v2/productsBySite — one business's catalog.
     */
    public function productsBySite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_id' => 'required|numeric|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::approved()->find($request->site_id);

        if (!$site) {
            return $this->sendError('Business not found.', '', 404);
        }

        $products = $this->baseQuery($request)
            ->where('products.site_id', $site->id)
            ->orderBy('products.sort_order')
            ->latest('products.created_at')
            ->paginateSafe();

        return $this->sendResponse($products, 'Products fetched.');
    }

    /**
     * POST /api/v2/featuredProducts — home screen rail.
     */
    public function featuredProducts(Request $request)
    {
        $products = $this->baseQuery($request)
            ->where('products.is_featured', true)
            ->latest('products.created_at')
            ->paginateSafe();

        return $this->sendResponse($products, 'Featured products fetched.');
    }

    // ── Engagement ───────────────────────────────────────────────────────────────

    /**
     * POST /api/v2/recordProductView
     *
     * Fire-and-forget. Recording starts on day one of the free period so there is data to
     * price on later (§9). Views are shown to vendors as value proof, never billed.
     */
    public function recordProductView(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'       => 'required|numeric|exists:products,id',
            'platform' => 'nullable|string|max:20',
            'referrer' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::live()->find($request->id);

        if (!$product) {
            return $this->sendError('Product not found.', '', 404);
        }

        DB::transaction(function () use ($request, $product) {
            ProductViewEvent::create([
                'product_id'   => $product->id,
                'user_id'      => auth()->id(),
                'session_hash' => $this->sessionHash($request),
                'platform'     => $request->platform,
                'referrer'     => $request->referrer,
                'ip_hash'      => $this->ipHash($request),
            ]);

            // denormalised for fast display; product_daily_stats is the reporting source
            $product->increment('views_count');
        });

        return $this->sendResponse(['id' => $product->id], 'Recorded.');
    }

    /**
     * POST /api/v2/recordProductLead
     *
     * The billable signal — a call, WhatsApp, directions tap or written enquiry. Vendors do
     * not feel an impression; they feel a phone call. See §9.
     */
    public function recordProductLead(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'        => 'required|numeric|exists:products,id',
            'lead_type' => ['required', 'string', 'in:' . implode(',', ProductLead::TYPES)],
            'message'   => 'nullable|string|max:1000',
            'platform'  => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::live()->find($request->id);

        if (!$product) {
            return $this->sendError('Product not found.', '', 404);
        }

        $lead = DB::transaction(function () use ($request, $product) {
            $lead = ProductLead::create([
                'product_id' => $product->id,
                'user_id'    => auth()->id(),
                'lead_type'  => $request->lead_type,
                'message'    => $request->message,
                'platform'   => $request->platform,
                'ip_hash'    => $this->ipHash($request),
            ]);

            $product->increment('leads_count');

            return $lead;
        });

        return $this->sendResponse(
            $lead->only(['id', 'lead_type']),
            'Thanks — the business has been notified of your interest.'
        );
    }

    // ── Query building ───────────────────────────────────────────────────────────

    /**
     * Live products with the columns a list card needs, plus distance when the caller sent
     * a location.
     */
    private function baseQuery(Request $request)
    {
        $query = Product::live()
            ->select([
                'products.id', 'products.site_id', 'products.product_category_id',
                'products.name', 'products.mr_name', 'products.slug',
                'products.base_price', 'products.sale_price', 'products.currency',
                'products.unit', 'products.is_featured', 'products.fulfilment_type',
                'products.views_count', 'products.leads_count', 'products.created_at',
            ])
            ->with([
                'productCategory:id,name,mr_name,code,booking_type',
                'site:id,name,mr_name,logo,latitude,longitude',
                'defaultVariant:id,product_id,price,sale_price,stock',
                'cover',
            ])
            ->withAvg('rating', 'rate')
            ->withCount('rating');

        if ($request->filled(['latitude', 'longitude'])) {
            $query->join('sites', 'sites.id', '=', 'products.site_id')
                  ->addSelect(DB::raw($this->distanceExpression() . ' as distance_km'))
                  ->addBinding([$request->latitude, $request->longitude, $request->latitude], 'select');

            if ($request->filled('radius_km')) {
                $query->havingRaw('distance_km <= ?', [$request->radius_km]);
            }
        }

        return $query;
    }

    /**
     * Haversine great-circle distance in kilometres.
     *
     * Computed in SQL rather than with a spatial index: at this catalogue size the
     * arithmetic is free, and it keeps `sites` free of geometry columns. Revisit if the
     * listing count reaches six figures.
     */
    private function distanceExpression(): string
    {
        // Rounded here rather than left to the client: raw double precision reaches the
        // app as 0.8652333325024, and User\V2\VendorController already returns 2 decimals.
        return sprintf(
            'ROUND(%d * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS(sites.latitude)) '
            . '* COS(RADIANS(sites.longitude) - RADIANS(?)) '
            . '+ SIN(RADIANS(?)) * SIN(RADIANS(sites.latitude)))), 2)',
            self::EARTH_RADIUS_KM
        );
    }

    private function applyFilters($query, Request $request): void
    {
        $query
            ->when($request->filled('product_category_id'),
                fn($q) => $q->where('products.product_category_id', $request->product_category_id))
            ->when($request->filled('category_code'),
                fn($q) => $q->whereHas('productCategory', fn($c) => $c->where('code', $request->category_code)))
            ->when($request->filled('site_id'),
                fn($q) => $q->where('products.site_id', $request->site_id))
            ->when($request->filled('booking_type'),
                fn($q) => $q->whereHas('productCategory', fn($c) => $c->where('booking_type', $request->booking_type)))
            ->when($request->boolean('is_featured'),
                fn($q) => $q->where('products.is_featured', true))
            ->when($request->filled('search'),
                fn($q) => $q->where(fn($w) => $w
                    ->where('products.name', 'like', "%{$request->search}%")
                    ->orWhere('products.mr_name', 'like', "%{$request->search}%")))
            // Price filters read the variant, not products.base_price — the variant carries
            // the authoritative price (R1, §3).
            ->when($request->filled('min_price'), fn($q) => $q->whereHas(
                'defaultVariant',
                fn($v) => $v->whereRaw('COALESCE(sale_price, price) >= ?', [$request->min_price])
            ))
            ->when($request->filled('max_price'), fn($q) => $q->whereHas(
                'defaultVariant',
                fn($v) => $v->whereRaw('COALESCE(sale_price, price) <= ?', [$request->max_price])
            ));
    }

    private function applySort($query, Request $request): void
    {
        match ($request->input('sort', 'latest')) {
            'price_asc'  => $query->orderBy(
                $this->variantPriceSubquery(), 'asc'
            ),
            'price_desc' => $query->orderBy(
                $this->variantPriceSubquery(), 'desc'
            ),
            'popular'    => $query->orderByDesc('products.leads_count')
                                  ->orderByDesc('products.views_count'),
            'nearest'    => $request->filled(['latitude', 'longitude'])
                                ? $query->orderBy('distance_km')
                                : $query->latest('products.created_at'),
            default      => $query->latest('products.created_at'),
        };
    }

    /**
     * Sort by the price a customer would actually pay, which lives on the default variant.
     */
    private function variantPriceSubquery()
    {
        return DB::table('product_variants')
            ->selectRaw('COALESCE(sale_price, price)')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('is_default', true)
            ->limit(1);
    }

    // ── Privacy helpers ──────────────────────────────────────────────────────────

    /**
     * IPs are personal data and nothing here needs to reverse them — deduplication only
     * needs equality, so store a keyed hash.
     */
    private function ipHash(Request $request): ?string
    {
        return $request->ip() ? hash_hmac('sha256', $request->ip(), config('app.key')) : null;
    }

    private function sessionHash(Request $request): string
    {
        return hash_hmac(
            'sha256',
            (auth()->id() ?? 'guest') . '|' . $request->ip() . '|' . $request->userAgent(),
            config('app.key')
        );
    }
}
