<?php

namespace App\Http\Controllers\User\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Gallery;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Vendor product management — the surface the React Native app drives.
 *
 * Products start as `draft` so a vendor can save a half-finished listing on a patchy Kokan
 * network and finish later; `submitProductForReview` moves them to `pending`.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §7 and §8.
 */
class ProductController extends BaseController
{
    public function __construct(private ProductService $products)
    {
        $this->middleware('auth:api');
    }

    /**
     * POST /api/v2/myProducts
     */
    public function myProducts(Request $request)
    {
        $products = Product::ownedBy(auth()->id())
            ->with(['productCategory:id,name,code,booking_type', 'site:id,name', 'defaultVariant', 'cover'])
            ->when($request->filled('site_id'), fn($q) => $q->where('site_id', $request->site_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->latest()
            ->paginateSafe();

        return $this->sendResponse($products, 'Products fetched.');
    }

    /**
     * POST /api/v2/getProduct
     */
    public function getProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with([
            'productCategory:id,name,code,booking_type,attribute_schema',
            'site:id,name,user_id',
            'variants',
            'gallery',
        ])->find($request->id);

        if (!auth()->user()->can('view', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        return $this->sendResponse($product, 'Product fetched.');
    }

    /**
     * POST /api/v2/addProduct
     */
    public function addProduct(Request $request)
    {
        $request->merge($this->decodeJsonInput($request));

        $validator = Validator::make($request->all(), $this->rules() + [
            'site_id'             => 'required|numeric|exists:sites,id',
            'product_category_id' => 'required|numeric|exists:product_categories,id',
            'name'                => 'required|string|between:2,150',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $site = Site::find($request->site_id);

        // Ability is resolved from the model class, so Product::class must be passed
        // explicitly — can('createOn', $site) would look for a SitePolicy instead.
        if (!auth()->user()->can('createOn', [Product::class, $site])) {
            return $this->sendError(
                'You can only add products to your own approved sites.',
                '',
                403
            );
        }

        $permission = $this->products->permissionFor($site, (int) $request->product_category_id);

        if (!$permission) {
            return $this->sendError(
                'This product category is not available for this site\'s categories.',
                '',
                422
            );
        }

        if ($this->products->quotaExceeded($site, $permission)) {
            return $this->sendError(
                "You have reached the limit of {$permission->max_products} products in this category for this site.",
                '',
                422
            );
        }

        $category = ProductCategory::find($request->product_category_id);

        [$attributes, $attributeErrors] = $this->products->validateAttributes(
            $category,
            $request->input('attributes') ?? []
        );

        if ($attributeErrors) {
            return $this->sendError($attributeErrors, '', 422);
        }

        $product = DB::transaction(function () use ($request, $site, $attributes) {
            $product = Product::create($this->payload($request) + [
                'site_id'             => $site->id,
                'product_category_id' => $request->product_category_id,
                'name'                => $request->name,
                'slug'                => $this->products->uniqueSlug($site->id, $request->name),
                'attributes'          => $attributes,
                'status'              => 'draft',
            ]);

            $this->products->syncDefaultVariant($product);

            return $product;
        });

        return $this->sendResponse(
            $product->load(['variants', 'productCategory:id,name,code']),
            'Product saved as a draft. Submit it for review when you are ready.'
        );
    }

    /**
     * POST /api/v2/updateProduct
     */
    public function updateProduct(Request $request)
    {
        $request->merge($this->decodeJsonInput($request));

        $validator = Validator::make($request->all(), $this->rules() + [
            'id'   => 'required|numeric|exists:products,id',
            'name' => 'sometimes|string|between:2,150',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site', 'productCategory')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $input = $this->payload($request);

        if ($request->has('attributes')) {
            [$attributes, $attributeErrors] = $this->products->validateAttributes(
                $product->productCategory,
                $request->input('attributes') ?? []
            );

            if ($attributeErrors) {
                return $this->sendError($attributeErrors, '', 422);
            }

            $input['attributes'] = $attributes;
        }

        if ($request->filled('name') && $request->name !== $product->name) {
            $input['name'] = $request->name;
            $input['slug'] = $this->products->uniqueSlug($product->site_id, $request->name, $product->id);
        }

        $wasApproved = $product->status === 'approved';

        DB::transaction(function () use ($product, $input) {
            $product->update($input + $this->products->statusAfterEdit($product));
            $this->products->syncDefaultVariant($product->fresh());
        });

        return $this->sendResponse(
            $product->fresh()->load(['variants', 'productCategory:id,name,code']),
            $wasApproved
                ? 'Product updated and sent for re-approval.'
                : 'Product updated.'
        );
    }

    /**
     * POST /api/v2/submitProductForReview
     */
    public function submitProductForReview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site', 'productCategory')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        if (!in_array($product->status, ['draft', 'rejected'], true)) {
            return $this->sendError('Only draft or rejected products can be submitted.', '', 422);
        }

        // A listing with no price is not reviewable — R1 means the price lives on the
        // default variant, so check there rather than on the product.
        if ($product->price === null) {
            return $this->sendError('Add a price before submitting this product.', '', 422);
        }

        $missing = $this->missingRequiredAttributes($product);

        if ($missing) {
            return $this->sendError(
                ['attributes' => ['Complete these required details first: ' . implode(', ', $missing)]],
                '',
                422
            );
        }

        $product->update(['status' => 'pending', 'rejection_reason' => null]);

        return $this->sendResponse($product, 'Product submitted for review.');
    }

    /**
     * Pause or resume an approved listing. POST /api/v2/toggleProductStatus
     */
    public function toggleProductStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        if (!in_array($product->status, ['approved', 'paused'], true)) {
            return $this->sendError('Only an approved product can be paused or resumed.', '', 422);
        }

        $product->update(['status' => $product->status === 'approved' ? 'paused' : 'approved']);

        return $this->sendResponse(
            $product->only(['id', 'status']),
            $product->status === 'paused' ? 'Product paused.' : 'Product is live again.'
        );
    }

    /**
     * POST /api/v2/deleteProduct
     */
    public function deleteProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('delete', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $product->delete();

        return $this->sendResponse($product->only(['id']), 'Product deleted.');
    }

    // ── Media (reuses the Gallery morph) ─────────────────────────────────────────

    /**
     * POST /api/v2/uploadProductMedia
     */
    public function uploadProductMedia(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'    => 'required|numeric|exists:products,id',
            'image' => 'required|mimes:jpeg,jpg,png,webp|max:4096',
            'title' => 'nullable|string|max:150',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $path = uploadFile($request->file('image'), config('constants.upload_path.product'))['path'];

        $media = $product->gallery()->create([
            // galleries.title is NOT NULL and the app does not prompt for a caption, so
            // fall back to the product name — it also serves as sensible alt text.
            'title'      => $request->title ?: $product->name,
            'path'       => $path,
            'status'     => true,
            'sort_order' => (int) $product->gallery()->max('sort_order') + 1,
            // the first image uploaded becomes the cover until the vendor says otherwise
            'is_cover'   => !$product->gallery()->where('is_cover', true)->exists(),
        ]);

        return $this->sendResponse($media, 'Image uploaded.');
    }

    /**
     * POST /api/v2/deleteProductMedia
     */
    public function deleteProductMedia(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'       => 'required|numeric|exists:products,id',
            'media_id' => 'required|numeric|exists:galleries,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $media = $product->gallery()->where('id', $request->media_id)->first();

        if (!$media) {
            return $this->sendError('Image not found on this product.', '', 404);
        }

        $wasCover = $media->is_cover;

        if ($media->path && Storage::exists($media->path)) {
            Storage::delete($media->path);
        }

        $media->delete();

        // never leave a product with images but no cover
        if ($wasCover) {
            $product->gallery()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
        }

        return $this->sendResponse(['id' => $request->media_id], 'Image deleted.');
    }

    /**
     * POST /api/v2/setProductCover  { id, media_id }
     */
    public function setProductCover(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'       => 'required|numeric|exists:products,id',
            'media_id' => 'required|numeric|exists:galleries,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $media = $product->gallery()->where('id', $request->media_id)->first();

        if (!$media) {
            return $this->sendError('Image not found on this product.', '', 404);
        }

        DB::transaction(function () use ($product, $media) {
            $product->gallery()->update(['is_cover' => false]);
            $media->update(['is_cover' => true]);
        });

        return $this->sendResponse($media->fresh(), 'Cover image updated.');
    }

    // ── Variants ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v2/saveProductVariant — creates when `variant_id` is absent.
     */
    public function saveProductVariant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'         => 'required|numeric|exists:products,id',
            'variant_id' => 'nullable|numeric|exists:product_variants,id',
            'name'       => 'required|string|max:120',
            'sku'        => 'nullable|string|max:60',
            'price'      => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'stock'      => 'nullable|integer|min:0',
            'is_default' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $data = $request->only(['name', 'sku', 'price', 'sale_price', 'stock', 'sort_order']);

        $variant = DB::transaction(function () use ($request, $product, $data) {
            if ($request->filled('variant_id')) {
                $variant = $product->variants()->where('id', $request->variant_id)->first();

                if (!$variant) {
                    return null;
                }

                $variant->update($data);
            } else {
                $variant = $product->variants()->create($data + ['status' => true]);
            }

            if ($request->boolean('is_default')) {
                $product->variants()->where('id', '!=', $variant->id)->update(['is_default' => false]);
                $variant->update(['is_default' => true]);
            }

            return $variant;
        });

        if (!$variant) {
            return $this->sendError('Variant not found on this product.', '', 404);
        }

        return $this->sendResponse($product->fresh()->load('variants'), 'Variant saved.');
    }

    /**
     * POST /api/v2/deleteProductVariant
     */
    public function deleteProductVariant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'         => 'required|numeric|exists:products,id',
            'variant_id' => 'required|numeric|exists:product_variants,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $product = Product::with('site')->find($request->id);

        if (!auth()->user()->can('update', $product)) {
            return $this->sendError('Product not found or not yours.', '', 404);
        }

        $variant = $product->variants()->where('id', $request->variant_id)->first();

        if (!$variant) {
            return $this->sendError('Variant not found on this product.', '', 404);
        }

        // R1 — a product must always keep a priced variant, or its price disappears.
        if ($product->variants()->count() <= 1) {
            return $this->sendError('A product must keep at least one variant.', '', 422);
        }

        $wasDefault = $variant->is_default;
        $variant->delete();

        if ($wasDefault) {
            $product->variants()->orderBy('sort_order')->first()?->update(['is_default' => true]);
        }

        return $this->sendResponse($product->fresh()->load('variants'), 'Variant deleted.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Validation rules shared by add and update.
     */
    private function rules(): array
    {
        return [
            'mr_name'        => 'nullable|string|between:2,150',
            'description'    => 'nullable|string|max:5000',
            'mr_description' => 'nullable|string|max:5000',
            'attributes'     => 'nullable|array',
            'base_price'     => 'nullable|numeric|min:0',
            'sale_price'     => 'nullable|numeric|min:0|lte:base_price',
            'unit'           => ['nullable', 'string', 'in:' . implode(',', Product::UNITS)],
            'available_from' => 'nullable|date',
            'available_to'   => 'nullable|date|after_or_equal:available_from',
            'sort_order'     => 'nullable|integer|min:0',
        ];
    }

    /**
     * Writable columns only.
     *
     * `status`, `is_featured` and `is_bookable` are deliberately absent — a vendor must not
     * be able to approve, feature, or make their own listing bookable by posting a field.
     */
    private function payload(Request $request): array
    {
        return $request->only([
            'mr_name', 'description', 'mr_description',
            'base_price', 'sale_price', 'unit',
            'available_from', 'available_to', 'sort_order',
        ]);
    }

    /**
     * Required schema attributes the product has not filled in yet.
     *
     * @return array<int, string>
     */
    private function missingRequiredAttributes(Product $product): array
    {
        $schema = $product->productCategory->attribute_schema ?? [];

        // `attributes` collides with Eloquent's internal $attributes property, so read it
        // through getAttribute() rather than $product->attributes — inside model code the
        // latter returns the raw internal array instead of the cast value.
        $filled  = $product->getAttribute('attributes') ?? [];
        $missing = [];

        foreach ($schema as $key => $spec) {
            if (($spec['required'] ?? false) && ($filled[$key] ?? null) === null) {
                $missing[] = $spec['label'] ?? $key;
            }
        }

        return $missing;
    }

    /**
     * Multipart requests send nested structures as JSON strings; normalise to arrays.
     */
    private function decodeJsonInput(Request $request, array $keys = ['attributes']): array
    {
        $merged = [];

        foreach ($keys as $key) {
            if (is_string($request->input($key))) {
                $merged[$key] = json_decode($request->input($key), true) ?? [];
            }
        }

        return $merged;
    }
}
