<?php

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\AllowedProductCategory;
use App\Models\ProductCategory;
use App\Services\ProductAttributeValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Admin management of the product taxonomy.
 *
 * A product category carries the `attribute_schema` that drives the vendor's Add-Product
 * form in the app, so creating one here is what ships a new vertical — no migration, no
 * code, no app release. See docs/VENDOR_PRODUCTS_DESIGN.md §6.
 */
class ProductCategoryController extends BaseController
{
    public function __construct(protected ProductAttributeValidator $attributeValidator)
    {
    }

    /**
     * POST /admin/v2/listProductCategories
     */
    public function listProductCategories(Request $request)
    {
        $categories = ProductCategory::query()
            ->with('parent:id,name,code')
            ->withCount('allowedCategories')
            ->when($request->filled('search'), fn($q) => $q->where(function ($w) use ($request) {
                $w->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
            }))
            ->when($request->filled('booking_type'), fn($q) => $q->where('booking_type', $request->booking_type))
            ->when($request->filled('status'), fn($q) => $q->where('status', (bool) $request->status))
            ->ordered()
            ->paginateSafe();

        return $this->sendResponse($categories, 'Product categories retrieved successfully...!');
    }

    /**
     * POST /admin/v2/getProductCategory
     */
    public function getProductCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:product_categories,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $category = ProductCategory::with([
            'parent:id,name,code',
            'children:id,parent_id,name,code',
            'siteCategories:id,name,code',
        ])->find($request->id);

        return $this->sendResponse($category, 'Product category retrieved successfully...!');
    }

    /**
     * POST /admin/v2/addProductCategory
     */
    public function addProductCategory(Request $request)
    {
        $request->merge($this->decodeJsonInput($request));

        $validator = Validator::make($request->all(), [
            'name'             => 'required|string|between:2,100',
            'mr_name'          => 'nullable|string|between:2,100',
            'code'             => 'required|string|max:60|unique:product_categories,code|regex:/^[a-z][a-z0-9_]*$/',
            'parent_id'        => 'nullable|numeric|exists:product_categories,id',
            'description'      => 'nullable|string|max:1000',
            'icon'             => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'attribute_schema' => 'nullable|array',
            'booking_type'     => ['nullable', 'string', 'in:' . implode(',', ProductCategory::BOOKING_TYPES)],
            'status'           => 'nullable|boolean',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        if ($schemaErrors = $this->attributeValidator->validateSchema($request->input('attribute_schema') ?? [])) {
            return $this->sendError(['attribute_schema' => $schemaErrors], '', 422);
        }

        $input         = $request->only([
            'name', 'mr_name', 'code', 'parent_id', 'description',
            'attribute_schema', 'booking_type', 'status', 'sort_order',
        ]);
        $input['slug'] = $this->uniqueSlug($request->name);

        if ($file = $request->file('icon')) {
            $input['icon'] = uploadFile($file, config('constants.upload_path.productCategory'))['path'];
        }

        $category = ProductCategory::create($input);

        return $this->sendResponse($category, 'Product category created successfully...!');
    }

    /**
     * POST /admin/v2/updateProductCategory
     */
    public function updateProductCategory(Request $request)
    {
        $request->merge($this->decodeJsonInput($request));

        $validator = Validator::make($request->all(), [
            'id'               => 'required|numeric|exists:product_categories,id',
            'name'             => 'sometimes|string|between:2,100',
            'mr_name'          => 'nullable|string|between:2,100',
            'code'             => 'sometimes|string|max:60|regex:/^[a-z][a-z0-9_]*$/|unique:product_categories,code,' . $request->id,
            'parent_id'        => 'nullable|numeric|exists:product_categories,id|different:id',
            'description'      => 'nullable|string|max:1000',
            'icon'             => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
            'attribute_schema' => 'nullable|array',
            'booking_type'     => ['nullable', 'string', 'in:' . implode(',', ProductCategory::BOOKING_TYPES)],
            'status'           => 'nullable|boolean',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        if ($request->has('attribute_schema')) {
            if ($schemaErrors = $this->attributeValidator->validateSchema($request->input('attribute_schema') ?? [])) {
                return $this->sendError(['attribute_schema' => $schemaErrors], '', 422);
            }
        }

        $category = ProductCategory::find($request->id);

        $input = $request->only([
            'name', 'mr_name', 'code', 'parent_id', 'description',
            'attribute_schema', 'booking_type', 'status', 'sort_order',
        ]);

        if ($request->filled('name') && $request->name !== $category->name) {
            $input['slug'] = $this->uniqueSlug($request->name, $category->id);
        }

        if ($file = $request->file('icon')) {
            $rawPath = $category->getRawOriginal('icon');
            if ($rawPath && Storage::exists($rawPath)) {
                Storage::delete($rawPath);
            }
            $input['icon'] = uploadFile($file, config('constants.upload_path.productCategory'))['path'];
        }

        $category->update($input);

        return $this->sendResponse($category->fresh(), 'Product category updated successfully...!');
    }

    /**
     * POST /admin/v2/deleteProductCategory
     */
    public function deleteProductCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:product_categories,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $category = ProductCategory::withCount('children')->find($request->id);

        if ($category->children_count > 0) {
            return $this->sendError(
                'Cannot delete a category that still has sub-categories. Move or delete them first.',
                '',
                422
            );
        }

        $category->delete();

        return $this->sendResponse($category, 'Product category deleted successfully...!');
    }

    /**
     * Replace the set of product categories allowed under a given site category.
     *
     * POST /admin/v2/setAllowedProductCategories
     * { category_id, allowed: [{product_category_id, is_required?, max_products?}, ...] }
     */
    public function setAllowedProductCategories(Request $request)
    {
        $request->merge($this->decodeJsonInput($request, ['allowed']));

        $validator = Validator::make($request->all(), [
            'category_id'                    => 'required|numeric|exists:categories,id',
            'allowed'                        => 'present|array',
            'allowed.*.product_category_id'  => 'required|numeric|exists:product_categories,id',
            'allowed.*.is_required'          => 'nullable|boolean',
            'allowed.*.max_products'         => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 422);
        }

        $allowed = collect($request->input('allowed'));

        if ($allowed->duplicates('product_category_id')->isNotEmpty()) {
            return $this->sendError('Duplicate product_category_id in payload.', '', 422);
        }

        DB::transaction(function () use ($request, $allowed) {
            AllowedProductCategory::where('category_id', $request->category_id)->delete();

            foreach ($allowed as $row) {
                AllowedProductCategory::create([
                    'category_id'         => $request->category_id,
                    'product_category_id' => $row['product_category_id'],
                    'is_required'         => $row['is_required'] ?? false,
                    'max_products'        => $row['max_products'] ?? null,
                ]);
            }
        });

        $result = AllowedProductCategory::with('productCategory:id,name,code,booking_type')
            ->where('category_id', $request->category_id)
            ->get();

        return $this->sendResponse($result, 'Allowed product categories updated successfully...!');
    }

    /**
     * Multipart requests send nested structures as JSON strings; normalise them to arrays.
     */
    private function decodeJsonInput(Request $request, array $keys = ['attribute_schema']): array
    {
        $merged = [];

        foreach ($keys as $key) {
            if (is_string($request->input($key))) {
                $merged[$key] = json_decode($request->input($key), true) ?? [];
            }
        }

        return $merged;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (ProductCategory::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
