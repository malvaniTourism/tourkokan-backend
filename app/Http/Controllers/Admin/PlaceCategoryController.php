<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlaceCategory;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class PlaceCategoryController extends BaseController
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
        $placeCategory = PlaceCategory::with(['places'])
                                        ->paginate(10);
        return $this->sendResponse($placeCategory, 'Place Category successfully Retrieved...!');  
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
            'icon' => 'required|mimes:jpeg,jpg,png|max:2048',
            'meta_data' => 'json',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
      
        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('icon')) {
            Log::info("inside upload icon");
            
            $icon = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('icon')->store(config('constants.upload_path.placecategory').$request->name);

            $input['icon'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['icon']);
        }

        $placeCategory = PlaceCategory::create($input);

        return $this->sendResponse($placeCategory, 'Place Category stored successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\PlaceCategory  $placeCategory
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $placeCategory = PlaceCategory::find($id);
        
        if (is_null($placeCategory)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($placeCategory, 'Place Category successfully Retrieved...!'); 
    }

    /**
     * Display a listing of the Places from catgory.
     *
     * @return \Illuminate\Http\Response
     */
    public function getAllPlaces($id)
    {
        $places = PlaceCategory::with('places')
                              ->whereId($id)
                              ->latest()
                              ->get();

        if (is_null($places)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($places, 'Places successfully Retrieved...!'); 
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\PlaceCategory  $placeCategory
     * @return \Illuminate\Http\Response
     */
    public function edit(PlaceCategory $placeCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\PlaceCategory  $placeCategory
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|between:2,100',
            'icon' => 'mimes:jpeg,jpg,png|max:2048',
            'meta_data' => 'json',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $placeCategory = PlaceCategory::find($id);

        if (is_null($placeCategory)) {
            return $this->sendError('Empty', [], 404);
        }
      
        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('icon')) {
            if(Storage::exists($placeCategory->icon)){
                Storage::delete($placeCategory->icon);
            }

            Log::info("inside upload icon");
            
            $icon = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('icon')->store(config('constants.upload_path.placecategory').$request->name);

            $input['icon'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['icon']);
        }

        $placeCategory->update($input);

        return $this->sendResponse($placeCategory, 'Place Category stored successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\PlaceCategory  $placeCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $placeCategory = PlaceCategory::find($id);

        if (is_null($placeCategory)) {
            return $this->sendError('Empty', [], 404);
        }

        if(Storage::exists($placeCategory->icon)){
            Storage::delete($placeCategory->icon);
        }

        $placeCategory->delete();

        return $this->sendResponse($placeCategory, 'Place Category deleted successfully...!');   
    }
}
