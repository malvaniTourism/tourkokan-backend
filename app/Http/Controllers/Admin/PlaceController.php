<?php

namespace App\Http\Controllers\Admin;

use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Validator;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BaseController as BaseController;

class PlaceController extends BaseController
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
        $places = Place::withCount(['photos', 'comments'])
                        ->with('photos','city')
                        ->paginate(10);
        return $this->sendResponse($places, 'Places successfully Retrieved...!');
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
            'city_id' => 'required|numeric|exists:cities,id',
            'description' => 'required|string',
            'rules' => 'json',
            'image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'bg_image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'price' => 'json',
            'rating' => 'numeric',
            'visitors_count' => 'numeric',
            'social_media' => 'json',
            'contact_details' => 'json',
            'place_category_id' => 'required|string|exists:place_categories,id',
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
            
            $image_url = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.places').$request->city_id.'/'.$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        //Image 2 store      
        if ($image = $request->file('bg_image_url')) {
            Log::info("inside upload bg_image_url");
            
            $bg_image_url = date('YmdHis') . "." . $image->getClientOriginalExtension();
            
            $path = $request->file('bg_image_url')->store(config('constants.upload_path.places').$request->city_id.'/'.$request->name);

            $input['bg_image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['bg_image_url']);
        }

        $place = Place::create($input);

        return $this->sendResponse($place, 'Place added successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Place  $place
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $place = Place::whereId($id)
                ->withCount(['photos', 'comments'])
                ->with('photos','city', 'comments')
                ->paginate(10);

        if (is_null($place)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($place, 'Place successfully Retrieved...!');  
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Place  $place
     * @return \Illuminate\Http\Response
     */
    public function edit(Place $place)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Place  $place
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|between:2,100',
            'city_id' => 'numeric',
            'description' => 'string',
            'rules' => 'json',
            'image_url' => 'mimes:jpeg,jpg,png|max:2048',
            'bg_image_url' => 'mimes:jpeg,jpg,png|max:2048',
            'price' => 'json',
            'rating' => 'numeric',
            'visitors_count' => 'numeric',
            'social_media' => 'json',
            'contact_details' => 'json',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $place = Place::find($id);

        if (is_null($place)) {
            return $this->sendError('Empty', [], 404);
        }

        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image_url')) {

            if(Storage::exists($place->image_url)){
                Storage::delete($place->image_url);
            }
        
            Log::info("inside upload image_url");
            
            $image_url = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.places').$request->city_id.'/'.$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        //Image 2 store      
        if ($image = $request->file('bg_image_url')) {

            if(Storage::exists($place->bg_image_url)){
                Storage::delete($place->bg_image_url);
            }
        
            Log::info("inside upload bg_image_url");
            
            $bg_image_url = date('YmdHis') . "." . $image->getClientOriginalExtension();
            
            $path = $request->file('bg_image_url')->store(config('constants.upload_path.places').$request->city_id.'/'.$request->name);

            $input['bg_image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['bg_image_url']);
        }

        $place->update($input);

        return $this->sendResponse($place, 'Projects updated successfully...!');   
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Place  $place
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $place = Place::find($id);

        if (is_null($place)) {
            return $this->sendError('Empty', [], 404);
        }

        if(Storage::exists($place->image_url)){
            Storage::delete($place->image_url);
        }

        if(Storage::exists($place->bg_image_url)){
            Storage::delete($place->bg_image_url);
        }
        
        $place->delete($request->all());

        return $this->sendResponse($place, 'place deleted successfully...!');   
    }
}
