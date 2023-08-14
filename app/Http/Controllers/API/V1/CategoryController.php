<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class CategoryController extends BaseController
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
        $categories = Category::paginate(10);
        return $this->sendResponse($categories, 'Categories successfully Retrieved...!');   
    }

    /**
     * Display a listing of the projects.
     *
     * @return \Illuminate\Http\Response
     */
    public function getAllProjects($id)
    {
        $projects = Category::with('projects')
                              ->whereId($id)
                              ->latest()
                              ->get();

        if (is_null($projects)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($projects, 'Projects successfully Retrieved...!'); 
    }

    public function getAllowedProductCategories($id)
    {
        $productCategory = Category::with('allowedproductCategory', 'allowedproductCategory.productCategory')
                            // ->where('category', $id)
                            ->latest()
                            ->get();

        if (is_null($productCategory)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($productCategory, 'Allowed Product Categories successfully Retrieved...!'); 
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
            'name' => 'required|string|between:2,100',
            'image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'description' => 'required|string',
            'meta_data' => 'json',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
      
        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image_url')) {
            Log::info("inside upload image_url");
            
            $image_url = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.category').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        $categories = Category::create($input);

        return $this->sendResponse($categories, 'Categories stored successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Categories  $categories
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $categories = Category::with('allowedproductCategory')
                            ->whereId($id)
                            ->latest()
                            ->get();
        
        if (is_null($categories)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($categories, 'Categories successfully Retrieved...!');   
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Categories  $categories
     * @return \Illuminate\Http\Response
     */
    public function edit(Categories $categories)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Categories  $categories
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|between:2,100',
            'image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'description' => 'required|string',
            'meta_data' => 'json',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $categories = Category::find($id);

        if (is_null($categories)) {
            return $this->sendError('Empty', [], 404);
        }

        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image_url')) {
            if(Storage::exists($categories->image_url)){
                Storage::delete($categories->image_url);
            }

            Log::info("inside upload image_url");
            
            $image_url = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.category').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        $categories->update($input);

        return $this->sendResponse($categories, 'Categories updated successfully...!');   
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Categories  $categories
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $categories = Category::find($id);

        if (is_null($categories)) {
            return $this->sendError('Empty', [], 404);
        }

        if(Storage::exists($categories->image_url)){
            Storage::delete($categories->image_url);
        }

        $categories->delete($request->all());

        return $this->sendResponse($categories, 'Categories deleted successfully...!');   
    }
}
