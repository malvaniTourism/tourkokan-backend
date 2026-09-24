<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController;
use App\Http\Middleware\VendorMiddleware;
use App\Models\ProductCategory;
use App\Models\Roles;
use App\Models\Site;
use App\Models\UserRoleRequest;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * First-time vendor onboarding from the web (M5).
 *
 * The app onboards step by step — request the role, wait, then add a business, then
 * products. The web wizard compresses that into one submission: business details plus an
 * optional first product, with the vendor role request raised alongside. Everything lands
 * in the same review queues (§2.6 — queues fill in parallel; the three gates keep it all
 * private until approved), so admins review exactly what they already review.
 *
 * Two guards keep this from becoming a second, parallel write path for established
 * vendors:
 *   - first-time only: the user must own zero sites;
 *   - web only: the combined form ships with `X-App-Source: web` — the app keeps its
 *     step-by-step flow.
 */
class VendorOnboardController extends BaseController
{
    public function __construct(private ProductService $products)
    {
    }

    /**
     * POST /api/v2/vendorOnboard
     */
    public function store(Request $request)
    {
        if (strtolower($request->header('X-App-Source', '')) !== 'web') {
            return $this->sendError('This onboarding flow is only available on the website. Please use the app\'s Become a Vendor flow.', '', 403);
        }

        $user   = auth()->user();
        $userId = $user->id;

        // Same contact gate as requestRole — a buyer must be able to reach the vendor.
        if ($missing = VendorMiddleware::missingContactFields($user)) {
            return response()->json(VendorMiddleware::incompleteProfileResponse($missing), 403);
        }

        // First-time only — anyone with a listing already has the step-by-step flow.
        if (Site::where('user_id', $userId)->exists()) {
            return $this->sendError('You already have a business listing. Manage it from My Businesses instead.', '', 422);
        }

        if (is_string($request->input('categories'))) {
            $request->merge(['categories' => json_decode($request->input('categories'), true)]);
        }

        $validator = Validator::make($request->all(), [
            // ── business (mirrors submitSite) ──
            'name' => [
                'required', 'string', 'between:2,100',
                Rule::unique('sites', 'name')->where(fn($q) => $q
                    ->where('user_id', $userId)
                    ->where('latitude', $request->latitude)
                    ->where('longitude', $request->longitude)),
            ],
            'categories'   => 'required|array|min:1',
            'categories.*' => 'exists:categories,id',
            'parent_id'    => 'nullable|exists:sites,id',
            'description'  => 'required|string|min:20',
            'tag_line'     => 'nullable|string|max:100',
            'domain_name'  => 'nullable|url|max:255',
            'phone'        => 'nullable|string|max:20|regex:/^[0-9+\-\s]+$/',
            'whatsapp'     => 'nullable|string|max:20|regex:/^[0-9+\-\s]+$/',
            'image'        => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'logo'         => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'pin_code'     => 'nullable|digits:6',
            // ── government verification (optional, M3) ──
            'reg_type'     => 'nullable|in:udyam,gstin,shop_act',
            'reg_number'   => 'required_with:reg_type|nullable|string|max:30|regex:/^[A-Za-z0-9\-\/]+$/',
            'reg_doc'      => 'nullable|mimes:jpeg,jpg,png,webp,pdf|max:4096',
            'consent'      => $request->filled('reg_type') ? 'accepted' : 'nullable',
            // ── optional first product ──
            'product'                        => 'nullable|array',
            'product.name'                   => 'required_with:product|string|between:2,150',
            'product.product_category_id'    => 'required_with:product|numeric|exists:product_categories,id',
            'product.description'            => 'nullable|string|max:5000',
            'product.base_price'             => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        // Product category must be reachable from the chosen business categories — checked
        // before anything is written so a bad pick rejects the whole submission cleanly.
        $productInput = $request->input('product');
        $attributes   = [];

        if ($productInput) {
            $productCategory = ProductCategory::find($productInput['product_category_id']);

            $allowed = \App\Models\AllowedProductCategory::query()
                ->whereIn('category_id', $request->input('categories'))
                ->where('product_category_id', $productCategory->id)
                ->exists();

            if (!$allowed) {
                return $this->sendError('This product category is not available for the business categories you chose.', '', 422);
            }

            [$attributes, $attributeErrors] = $this->products->validateAttributes(
                $productCategory,
                $productInput['attributes'] ?? []
            );

            if ($attributeErrors) {
                return $this->sendError($attributeErrors, '', 422);
            }
        }

        $siteInput = $request->except([
            'categories', 'product', 'consent',
            'reg_type', 'reg_number', 'reg_doc', 'verification_status', 'verified_at',
        ]);
        $siteInput['user_id']           = $userId;
        $siteInput['status']            = false;
        $siteInput['submission_status'] = 'pending';

        if ($request->filled('reg_type')) {
            $siteInput['reg_type']            = $request->reg_type;
            $siteInput['reg_number']          = trim($request->reg_number);
            $siteInput['verification_status'] = 'pending';
            $siteInput['meta_data']           = [
                'verification' => [
                    'consent_at'   => now()->toDateTimeString(),
                    'submitted_at' => now()->toDateTimeString(),
                ],
            ];
        }

        foreach (['logo', 'image'] as $field) {
            if ($file = $request->file($field)) {
                $siteInput[$field] = uploadFile($file, config('constants.upload_path.site'))['path'];
            }
        }

        if ($doc = $request->file('reg_doc')) {
            $siteInput['reg_doc'] = uploadFile($doc, config('constants.upload_path.site_docs'))['path'];
        }

        [$site, $product, $roleRequest] = DB::transaction(function () use ($request, $user, $userId, $siteInput, $productInput, $attributes) {
            $site = Site::create($siteInput);
            $site->categories()->attach($request->input('categories'));

            $product = null;
            if ($productInput) {
                $product = $site->products()->create([
                    'product_category_id' => $productInput['product_category_id'],
                    'name'                => $productInput['name'],
                    'slug'                => $this->products->uniqueSlug($site->id, $productInput['name']),
                    'description'         => $productInput['description'] ?? null,
                    'base_price'          => $productInput['base_price'] ?? null,
                    'attributes'          => $attributes,
                    'status'              => 'draft',
                ]);
                $this->products->syncDefaultVariant($product);
            }

            // Raise the vendor role request alongside — unless one is already open or the
            // role is already held (a vendor with zero sites, e.g. after deleting them).
            $roleRequest = null;
            if (!$user->hasRole('vendor')) {
                $roleRequest = UserRoleRequest::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'role_id' => Roles::where('code', 'vendor')->value('id'),
                        'status'  => 'pending',
                    ],
                    ['reason' => 'Web onboarding wizard']
                );
            }

            return [$site, $product, $roleRequest];
        });

        return $this->sendResponse([
            'site'         => $site->load('categories:id,name,code'),
            'product'      => $product?->load('variants'),
            'role_request' => $roleRequest,
        ], 'Your business has been submitted and is under review. We will notify you once approved.');
    }
}
