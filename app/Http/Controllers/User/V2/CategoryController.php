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
    public function listcategories()
    {
        if (!Cache::has('categories')) {
            $categories = Cache::remember('categories', 60, function () {
                $categories = Category::with(['subCategories:id,name,parent_id,icon,is_hot_category'])
                    ->select('*')
                    ->whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])
                    ->whereNull('parent_id')
                    ->paginate(10);

                return $categories;
            });
        }

        $categories = Cache::get('categories');

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

        if (!Cache::has('subCategories')) {
            $subCategories = Cache::remember('subCategories', 60, function () use ($request) {
                $subCategories = Category::with(['subCategories:id,name,parent_id,icon,is_hot_category'])
                    ->find($request->id);

                return $subCategories;
            });
        }

        $subCategories = Cache::get('subCategories');

        if (!$subCategories) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($subCategories, 'Sub Categories successfully Retrieved...!');
    }
}
