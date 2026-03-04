<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CategoryController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listcategories(Request $request)
    {
        $cacheKey = 'categories_' . ($request->category ?? 'all') . '_page_' . $request->get('page', 1);

        $categories = Cache::remember($cacheKey, 86400, function () use ($request) {
            $with = ['subCategories:id,name,mr_name,code,parent_id,icon,is_hot_category'];

            if ($request->has('category')) {
                $with['subCategories.sites'] = fn($q) => $q->select('id', 'name', 'meta_data');
            }

            $categories = Category::with($with)
                ->select('*')
                ->whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
                ->whereStatus(true)
                ->when($request->has('category'), fn($q) => $q->where('code', $request->category))
                ->when(!$request->has('category'), fn($q) => $q->whereNull('parent_id'))
                ->paginate(10);

            if ($request->has('category')) {
                $categories->getCollection()->transform(function ($category) {
                    $category->subCategories->transform(function ($subCategory) {
                        $subCategory->setRelation('sites', $subCategory->sites->take(15));
                        return $subCategory;
                    });
                    return $category;
                });
            }

            return $categories;
        });

        if (!$categories) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($categories, 'Categories successfully Retrieved...!');
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
