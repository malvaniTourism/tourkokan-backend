<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Services\CategoryService;

class CategoryController extends BaseController
{
    public function __construct(protected CategoryService $categoryService) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listcategories(Request $request)
    {
        $categories = $this->categoryService->getPaginated(
            $request->category,
            $request->get('page', 1),
            $request->get('per_page', 15),
            [
                'include_empty' => $request->boolean('include_empty'),
            ]
        );

        return $this->sendResponse($categories, 'Categories successfully Retrieved...!');
    }

    /**
     * POST /api/v2/businessCategories
     *
     * The "Register a business" picker. Returns only vendor-registrable categories
     * (is_business = true) as a flat parent -> children tree, skipping the directory-only
     * branches (Destination, Emergency, Government, Education, Transportation) a vendor
     * cannot list products under. See docs/marketplace-backend-asks.md #4.
     */
    public function businessCategories()
    {
        $categories = \App\Models\Category::where('is_business', true)
            ->whereNull('parent_id')
            ->whereStatus(true)
            ->with(['subCategories' => fn($q) => $q
                ->where('is_business', true)->whereStatus(true)
                ->select('id', 'name', 'mr_name', 'code', 'parent_id', 'icon', 'is_business')
                ->orderBy('name')])
            ->orderBy('id')
            ->get(['id', 'name', 'mr_name', 'code', 'icon', 'is_business']);

        return $this->sendResponse($categories, 'Business categories fetched.');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\new_category  $new_category
     * @return \Illuminate\Http\Response
     */
    public function getCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:categories,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $subCategories = Cache::remember('subCategories_' . $request->id, 86400, function () use ($request) {
            return Category::with(['subCategories:id,name,mr_name,code,parent_id,icon,is_hot_category'])
                ->find($request->id);
        });

        return $this->sendResponse($subCategories, 'Sub Categories successfully Retrieved...!');
    }
}
