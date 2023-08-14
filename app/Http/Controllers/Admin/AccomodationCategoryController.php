<?php

namespace App\Http\Controllers\Admin;

use App\Models\AccomodationCategory;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class AccomodationCategoryController extends BaseController
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
        $accomodationCategory = AccomodationCategory::paginate(10);

        return $this->sendResponse($accomodationCategory, 'Accomodation Category successfully Retrieved...!');  
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
            'image_url' => 'required|mimes:jpeg,jpg,png,webp|max:2048',
            'description' => 'required|string',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
      
        $input = $request->all();
        $date = currentDate();
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image_url')) {
            Log::info("inside upload image_url");
            
            $image_url = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.accomCategory').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        $accomodationcategory = AccomodationCategory::create($input);

        return $this->sendResponse($accomodationcategory, 'Accomodation Category stored successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\AccomodationCategory  $accomodationCategory
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $accomodationcategory = AccomodationCategory::find($id);
        
        if (is_null($accomodationcategory)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($accomodationcategory, 'Accomodation Category successfully Retrieved...!'); 
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\AccomodationCategory  $accomodationCategory
     * @return \Illuminate\Http\Response
     */
    public function edit(AccomodationCategory $accomodationCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\AccomodationCategory  $accomodationCategory
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|between:2,100',
            'image_url' => 'mimes:jpeg,jpg,png,webp|max:2048',
            'description' => 'nullable|string',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $accomodationcategory = AccomodationCategory::find($id);

        if (is_null($accomodationcategory)) {
            return $this->sendError('Empty', [], 404);
        }
      
        $input = $request->all();
        $date = currentDate();
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image_url')) {
            if(Storage::exists($accomodationcategory->image_url)){
                Storage::delete($accomodationcategory->image_url);
            }

            Log::info("inside upload image_url");
            
            $image_url = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.accomCategory').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        $accomodationcategory->update($input);

        return $this->sendResponse($accomodationcategory, 'Accomodation Category stored successfully...!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\AccomodationCategory  $accomodationCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $accomodationcategory = AccomodationCategory::find($id);

        if (is_null($accomodationcategory)) {
            return $this->sendError('Empty', [], 404);
        }

        if(Storage::exists($accomodationcategory->image_url)){
            Storage::delete($accomodationcategory->image_url);
        }

        $accomodationcategory->delete();

        return $this->sendResponse($accomodationcategory, 'Accomodation Category deleted successfully...!');   
    }
}
