<?php

namespace App\Http\Controllers\Admin\V2;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
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
        $categories = Category::whereNotIn('code', ['country', 'state', 'city', 'district', 'village', 'area'])->paginate(10);
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
            'id' => 'required|exists:new_categories,id'
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $categories = Category::find($request->id);

        if (!$categories) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($categories, 'Categories successfully Retrieved...!');
    }
}
