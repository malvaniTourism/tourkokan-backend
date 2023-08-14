<?php

namespace App\Http\Controllers\Admin;

use App\Models\City;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CityController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    // public function __construct() {
    //     $this->middleware('auth:api');
    // }
    
     /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $cities = City::withCount(['projects', 'places', 'photos', 'comments'])
                        ->latest()                
                        ->paginate(10);

        return $this->sendResponse($cities, 'Cities successfully Retrieved...!');  
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
            'name' => 'required|string|unique:cities',
            'tag_line' => 'required|string',
            'description' => 'required|string',
            'image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'bg_image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'url' => 'string',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
      
        $input = $request->all();

        $date = currentDate(); //for unique naming of project folder

        // Image 1 store      
        if ($image = $request->file('image_url')) {
            Log::info("inside upload image_url");
            
            $image_url = $request->name.$date. "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.city').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        // Image 2 store      
        if ($image = $request->file('bg_image_url')) {
            Log::info("inside upload bg_image_url");
            
            $bg_image_url = $request->name."." . $image->getClientOriginalExtension();

            $path = $request->file('bg_image_url')->store(config('constants.upload_path.city').$request->name);

            $input['bg_image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['bg_image_url']);
        }

        //inserting into table
        $city = City::create($input);

        return $this->sendResponse($city, 'City added successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $city = City::withCount(['projects', 'places', 'photos', 'comments'])
                    // ->with(['projects', 'places', 'photos', 'comments'])
                    ->whereId($id)
                    ->latest()
                    ->paginate(10);

        return $this->sendResponse($city, 'Cities successfully Retrieved...!');  
    }

     /**
     * Display a listing of the city.
     *
     * @return \Illuminate\Http\Response
     */
    public function getAllcities($id)
    {
        $cities = City::withCount(['projects', 'places', 'photos', 'comments'])
                        ->with(['projects', 'places', 'photos', 'comments'])
                        ->whereId($id)
                        ->latest()
                        ->paginate(10);
        
        if (is_null($cities)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($cities, 'Cities successfully Retrieved...!'); 
    }
    
    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function edit(City $city)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        Log::info($request->all());
        $validator = Validator::make($request->all(), [
            'name' => 'string|unique:cities,name,' . $id . ',id',
            'tag_line' => 'string',
            'description' => 'string',
            'image_url' => 'mimes:jpeg,jpg,png|max:2048',
            'bg_image_url' => 'mimes:jpeg,jpg,png|max:2048',
            'url' => 'string',
        ]);
        
        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $cities = City::find($id);

        if (is_null($cities)) {
            return $this->sendError('Empty', [], 404);
        }

        $input = $request->all();

        $date = currentDate(); //for unique naming of project folder

        Log::info("upload file starting");

        // Image 1 store      
        if ($image = $request->file('image_url')) {

            if(Storage::exists($cities->image_url)){
                Storage::delete($cities->image_url);
            }
    
            Log::info("inside upload image_url");
            
            $image_url = $request->name.$date. "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.city').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        // Image 2 store      
        if ($image = $request->file('bg_image_url')) {
            
            if(Storage::exists($cities->bg_image_url)){
                Storage::delete($cities->bg_image_url);
            }

            Log::info("inside upload bg_image_url");
            
            $bg_image_url = $request->name.$date."." . $image->getClientOriginalExtension();

            $path = $request->file('bg_image_url')->store(config('constants.upload_path.city').$request->name);

            $input['bg_image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['bg_image_url']);
        }



        $cities->update($input);

        return $this->sendResponse($cities, 'City updated successfully...!');   
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $cities = City::find($id);

        if (is_null($cities)) {
            return $this->sendError('Empty', [], 404);
        }

        if(Storage::exists($cities->image_url)){
            Storage::delete($cities->image_url);
        }

        if(Storage::exists($cities->bg_image_url)){
            Storage::delete($cities->bg_image_url);
        }
        
        $cities->delete($request->all());

        return $this->sendResponse($cities, 'City deleted successfully...!');   
    }
}
