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
            $request->get('page', 1)
        );

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
