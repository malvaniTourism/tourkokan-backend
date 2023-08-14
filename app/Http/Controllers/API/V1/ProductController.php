<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProductController extends BaseController
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
            'project_id' => 'required|nullable|numeric|exists:projects,id',
            'product_category_id' => 'required|nullable|numeric|exists:product_categories,id',
            'productable_type' => 'required|string',
            'productable_id' => 'required|numeric',
            'meta_data' => 'nullable|string',
        ]);            

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        // $data = json_encode(DB::table($request->productable_type)->find($request->productable_id));//getData($request->productable_id, $request->productable_type);
        $data = getData($request->productable_id, $request->productable_type);

        if (!$data) {
            return $this->sendError($request->productable_type.' Not Exist..!', '', 400);       
        }

        $product = new Product;

        $product->project_id = $request->get('project_id');
        
        $product->product_category_id = $request->get('product_category_id');
        
        $product->meta_data = $request->get('meta_data');

        $product->productable()->associate($data);

        // $product = $product->save();       
        $product = Product::create(json_decode($product, true));       

        return $this->sendResponse($product, 'Product created successfully...!');    
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Products  $products
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $products = Product::with(['projects', 'productCategory', 'productable.comments'])
                            ->groupBy('productable_type')
                            ->find($id);
        
        if (is_null($products)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($products, 'Product successfully Retrieved...!'); 
    }

     /**
     * Display a listing of the products.
     *
     * @return \Illuminate\Http\Response
     */
    public function getAllProductsByProjectId(Request $request, $id)
    {
        $products = Product::with(['productable'])
                            ->where([['project_id', '=', $id],
                                    ['productable_type', '=', $request->type]])
                            ->latest()
                            ->paginate(10);
        
        if (is_null($products)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($products, 'Products successfully Retrieved...!'); 
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Products  $products
     * @return \Illuminate\Http\Response
     */
    public function edit(Products $products)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Products  $products
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Products $products)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Products  $products
     * @return \Illuminate\Http\Response
     */
    public function destroy(Products $products)
    {
        //
    }
}
