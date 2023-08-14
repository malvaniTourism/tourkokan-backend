<?php

namespace App\Http\Controllers\Admin;

use App\Models\AllowedProductCategory;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class AllowedProductCategoryController extends BaseController
{
     /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
       //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|numeric|exists:categories,id',
            'product_category_id' => 'required|numeric|exists:product_categories,id'
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $allowedProductCategory = AllowedProductCategory::create($request->all());

        return $this->sendResponse($allowedProductCategory, 'Product category assigned successfully...!');    
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\AllowedProductCategory  $allowedProductCategory
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\AllowedProductCategory  $allowedProductCategory
     * @return \Illuminate\Http\Response
     */
    public function edit(AllowedProductCategory $allowedProductCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\AllowedProductCategory  $allowedProductCategory
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'numeric|exists:categories,id',
            'product_category_id' => 'numeric|exists:product_categories,id'
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $allowedProductCategory = AllowedProductCategory::find($id);

        if (is_null($contact)) {
            return $this->sendError('Empty', [], 404);
        }

        $allowedProductCategory->update($request->all());

        return $this->sendResponse($allowedProductCategory, 'Product category assigned successfully...!');    
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\AllowedProductCategory  $allowedProductCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $allowedProductCategory = AllowedProductCategory::find($id);

        if (is_null($allowedProductCategory)) {
            return $this->sendError('Empty', [], 404);
        }

        $allowedProductCategory->delete($id);

        return $this->sendResponse($allowedProductCategory, 'AllowedProductCategory deleted successfully...!');   
    }
}
