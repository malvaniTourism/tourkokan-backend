<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CategoryController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

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
     * @param  \App\Models\category  $category
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

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:categories,name|string|between:2,100',
            'parent_id' => 'required|string|exists:categories,id',
            'description' => 'required|string',
            'icon' => 'nullable|mimes:jpeg,jpg,png|max:512',
            'status' => 'boolean:true,false',
            'meta_data' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->all();

        $uploadPath = config('constants.upload_path.category');

        $fileFields = ['icon'];

        foreach ($fileFields as $field) {
            if ($image = $request->file($field)) {
                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        $input['code'] = strtolower($request->name);

        $category = Category::create($input);

        Cache::forget(['categories', 'subCategories']);

        return $this->sendResponse($category, 'Category added successfully...!');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:categories,id',
            'name' => 'sometimes|string|between:2,100',
            'parent_id' => 'sometimes|string|exists:categories,id',
            'description' => 'sometimes|string',
            'icon' => 'sometimes|nullable|mimes:jpeg,jpg,png|max:512',
            'status' => 'sometimes|boolean:true,false',
            'meta_data' => 'sometimes|nullable|json'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $input = $request->all();

        $category = Category::find($request->id);

        $uploadPath = config('constants.upload_path.category');

        $fileFields = ['icon'];

        foreach ($fileFields as $field) {
            if ($image = $request->file($field)) {
                $currentFilePath = $category->$field;

                if (Storage::exists($currentFilePath)) {
                    Storage::delete($currentFilePath);
                }

                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        if ($request->has('name')) {
            $input['code'] = strtolower($request->name);
        }

        $category->update($input);

        Cache::forget(['categories', 'subCategories']);

        return $this->sendResponse($category, 'Category updated successfully...!');
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\Response
     */
    public function deleteCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:categories,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $category = Category::find($request->id);

        if (!$category) {
            return $this->sendError('Empty', [], 404);
        }

        if (Storage::exists($category->icon)) {
            Storage::delete($category->icon);
        }

        $category->delete($request->id);

        Cache::forget(['categories', 'subCategories']);

        return $this->sendResponse($category, 'Category deleted successfully...!');
    }
}
