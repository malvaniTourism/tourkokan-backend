<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Public vendor profiles — one owner, several businesses.
 *
 * A vendor is a user holding the `vendor` role; their businesses are the sites they own.
 * Only approved, published sites and live products are ever exposed.
 *
 * Identity here is deliberately taken from the vendor's **primary business**, not from the
 * user record: `users.name`, `email` and `mobile` are encrypted personal data, and a tourist
 * has no business seeing the owner's name — they are looking for "Sagar Resort Group", not
 * for a person. See docs/VENDOR_PRODUCTS_DESIGN.md §2.
 */
class VendorController extends BaseController
{
    private const EARTH_RADIUS_KM = 6371;

    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * POST /api/v2/listVendors
     *
     * Vendors with at least one live business. Filters: search, category_id/category_code,
     * latitude+longitude(+radius_km).
     */
    public function listVendors(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search'        => 'nullable|string|max:100',
            'category_id'   => 'nullable|numeric|exists:categories,id',
            'category_code' => 'nullable|string|max:60',
            'latitude'      => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude'     => 'nullable|numeric|between:-180,180|required_with:latitude',
            'radius_km'     => 'nullable|numeric|min:0.1|max:500',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        // Resolve the vendors through their live sites, so someone whose only business is
        // still pending does not appear at all.
        $siteQuery = Site::approved()
            ->whereNotNull('user_id')
            ->when($request->filled('category_id'),
                fn($q) => $q->whereHas('categories', fn($c) => $c->where('categories.id', $request->category_id)))
            ->when($request->filled('category_code'),
                fn($q) => $q->whereHas('categories', fn($c) => $c->where('code', $request->category_code)))
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', "%{$request->search}%"));

        if ($request->filled(['latitude', 'longitude']) && $request->filled('radius_km')) {
            $siteQuery->whereRaw(
                $this->distanceExpression() . ' <= ?',
                [$request->latitude, $request->longitude, $request->latitude, $request->radius_km]
            );
        }

        $userIds = $siteQuery->distinct()->pluck('user_id');

        $vendors = User::whereIn('id', $userIds)
            ->whereHas('roles', fn($q) => $q->where('code', 'vendor'))
            ->latest('id')
            ->paginateSafe();

        $ids     = collect($vendors->items())->pluck('id');
        $summary = $this->summaryFor($ids, $request);

        $vendors->getCollection()->transform(fn(User $v) => $summary[$v->id] ?? $this->emptyCard($v));

        return $this->sendResponse($vendors, 'Vendors fetched.');
    }

    /**
     * POST /api/v2/vendorProfile  { id }
     *
     * One vendor, all their live businesses, and their catalog across all of them.
     */
    public function vendorProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'        => 'required|numeric|exists:users,id',
            'latitude'  => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $vendor = User::find($request->id);

        if (!$vendor->hasRole('vendor')) {
            return $this->sendError('Vendor not found.', '', 404);
        }

        $sites = Site::ownedBy($vendor->id)
            ->approved()
            ->with('categories:id,name,mr_name,code,parent_id,icon')
            ->withCount(['products' => fn($q) => $q->live()])
            ->withAvg('rating', 'rate')
            ->orderByDesc('is_primary')
            ->get([
                'id', 'name', 'mr_name', 'tag_line', 'mr_tag_line', 'description', 'mr_description',
                'logo', 'image', 'is_primary', 'parent_id',
                'latitude', 'longitude', 'pin_code', 'domain_name', 'social_media',
            ]);

        if ($sites->isEmpty()) {
            return $this->sendError('This vendor has no live businesses yet.', '', 404);
        }

        if ($request->filled(['latitude', 'longitude'])) {
            $sites->each(fn($s) => $s->distance_km = $this->distanceTo(
                $request->latitude, $request->longitude, $s->latitude, $s->longitude
            ));
        }

        $primary = $sites->firstWhere('is_primary', true) ?? $sites->first();

        $products = Product::live()
            ->whereIn('site_id', $sites->pluck('id'))
            ->with([
                'productCategory:id,name,mr_name,code,booking_type',
                'site:id,name',
                'defaultVariant:id,product_id,price,sale_price,stock',
                'cover',
            ])
            ->withAvg('rating', 'rate')
            ->orderByDesc('is_featured')
            ->latest()
            ->paginateSafe();

        return $this->sendResponse([
            // Business identity, never the owner's personal details.
            'vendor' => [
                'id'            => $vendor->id,
                'business_name' => $primary->name,
                'mr_name'       => $primary->mr_name,
                'tag_line'      => $primary->tag_line,
                'logo'          => $primary->logo,
                'image'         => $primary->image,
                'description'   => $primary->description,
                'social_media'  => $primary->social_media,
                'domain_name'   => $primary->domain_name,
                'member_since'  => $vendor->created_at,
                'outlet_count'  => $sites->count(),
                'product_count' => $products->total(),
                'categories'    => $sites->pluck('categories')->flatten()->unique('id')->values(),
            ],
            'outlets'  => $sites,
            'products' => $products,
        ], 'Vendor profile fetched.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * List-card data for a page of vendors, in two queries rather than per row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function summaryFor($userIds, Request $request): array
    {
        $sites = Site::approved()
            ->whereIn('user_id', $userIds)
            ->with('categories:id,name,mr_name,code')
            ->orderByDesc('is_primary')
            ->get(['id', 'user_id', 'name', 'mr_name', 'tag_line', 'logo', 'image',
                   'is_primary', 'latitude', 'longitude'])
            ->groupBy('user_id');

        $productCounts = DB::table('products')
            ->join('sites', 'sites.id', '=', 'products.site_id')
            ->whereIn('sites.user_id', $userIds)
            ->where('products.status', 'approved')
            ->whereNull('products.deleted_at')
            ->where('sites.status', true)
            ->where('sites.submission_status', 'approved')
            ->selectRaw('sites.user_id, COUNT(*) c')
            ->groupBy('sites.user_id')
            ->pluck('c', 'user_id');

        $out = [];

        foreach ($sites as $userId => $owned) {
            $primary = $owned->firstWhere('is_primary', true) ?? $owned->first();

            $card = [
                'id'            => (int) $userId,
                'business_name' => $primary->name,
                'mr_name'       => $primary->mr_name,
                'tag_line'      => $primary->tag_line,
                'logo'          => $primary->logo,
                'image'         => $primary->image,
                'outlet_count'  => $owned->count(),
                'product_count' => (int) ($productCounts[$userId] ?? 0),
                'categories'    => $owned->pluck('categories')->flatten()->unique('id')->values(),
            ];

            if ($request->filled(['latitude', 'longitude'])) {
                // distance to the nearest of this vendor's outlets
                // filter() with no callback would drop 0.0 as falsy — an outlet standing
                // exactly where the user is, which is the one case that matters most.
                $card['distance_km'] = $owned
                    ->map(fn($s) => $this->distanceTo($request->latitude, $request->longitude, $s->latitude, $s->longitude))
                    ->filter(fn($d) => $d !== null)
                    ->min();
            }

            $out[(int) $userId] = $card;
        }

        return $out;
    }

    private function emptyCard(User $vendor): array
    {
        return [
            'id'            => $vendor->id,
            'business_name' => null,
            'outlet_count'  => 0,
            'product_count' => 0,
            'categories'    => [],
        ];
    }

    private function distanceExpression(): string
    {
        return sprintf(
            '(%d * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS(sites.latitude)) '
            . '* COS(RADIANS(sites.longitude) - RADIANS(?)) '
            . '+ SIN(RADIANS(?)) * SIN(RADIANS(sites.latitude)))))',
            self::EARTH_RADIUS_KM
        );
    }

    private function distanceTo($lat1, $lng1, $lat2, $lng2): ?float
    {
        if ($lat2 === null || $lng2 === null) {
            return null;
        }

        $angle = acos(min(1,
            cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2))
            * cos(deg2rad((float) $lng2) - deg2rad((float) $lng1))
            + sin(deg2rad((float) $lat1)) * sin(deg2rad((float) $lat2))
        ));

        return round(self::EARTH_RADIUS_KM * $angle, 2);
    }
}
