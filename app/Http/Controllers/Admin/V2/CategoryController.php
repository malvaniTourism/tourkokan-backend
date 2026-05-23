<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function listcategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'nullable|boolean:true,false',
            'apitype' => 'required|string|in:list,dropdown',
        ]);

        // return $request->all();

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $categories = Category::select(isValidReturn(config('grid.categories.' . $request->apitype), 'columns', '*'))
            ->whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area', 'destination']);

        if ($request->apitype === 'list') {
            $categories = $categories->with(['subCategories:id,name,mr_name,code,parent_id,icon,status,is_hot_category']);
        }

        if ($request->parent_id) {
            $categories = $categories->where('parent_id', $request->parent_id);
        } else {
            $categories = $categories->whereNull('parent_id');
        }

        if ($request->status != null) {
            $categories = $categories->whereStatus($request->status);
        }

        $categories = $categories->paginate($request->per_page);

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

        $subCategories = Category::with(['allSubCategories:id,name,parent_id,icon,status,is_hot_category'])
            ->find($request->id);

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
            'name' => ['required', 'string', 'between:2,100', Rule::unique('categories', 'name')->whereNull('deleted_at')],
            'parent_id' => 'sometimes|integer|exists:categories,id',
            'description' => 'required|string',
            'icon' => 'nullable|mimes:jpeg,jpg,png,webp|max:512',
            'status' => 'boolean',
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
            'name' => ['sometimes', 'string', 'between:2,100', Rule::unique('categories', 'name')->ignore($request->id)->whereNull('deleted_at')],
            'parent_id' => 'sometimes|integer|exists:categories,id',
            'description' => 'sometimes|string',
            'icon' => 'sometimes|nullable|mimes:jpeg,jpg,png,webp|max:512',
            'status' => 'sometimes|boolean',
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
                $rawPath = $category->getRawOriginal($field);
                if ($rawPath && Storage::exists($rawPath)) {
                    Storage::delete($rawPath);
                }
                $input[$field] = uploadFile($image, $uploadPath)['path'];
            }
        }

        if ($request->has('name')) {
            $input['code'] = strtolower($request->name);
        }

        $category->update($input);

        Cache::forget('subCategories_' . $request->id);

        return $this->sendResponse($category->refresh(), 'Category updated successfully...!');
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

        $rawIcon = $category->getRawOriginal('icon');
        if ($rawIcon && Storage::exists($rawIcon)) {
            Storage::delete($rawIcon);
        }

        Cache::forget('subCategories_' . $request->id);

        $category->delete();

        return $this->sendResponse($category, 'Category deleted successfully...!');
    }
}
