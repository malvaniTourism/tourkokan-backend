<?php

namespace App\Http\Controllers\Admin;

use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Validator;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BaseController as BaseController;

class FoodController extends BaseController
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
        $food = Food::paginate(10);

        return $this->sendResponse($food, 'Food successfully Retrieved...!'); 
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
            'food_type' => 'required|string',
            'description' => 'required|string',
            'nuetritional_info' => 'json',
            'image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'visitor_count' => 'numeric',
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

            $path = $request->file('image_url')->store(config('constants.upload_path.food').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        $food = Food::create($input);

        return $this->sendResponse($food, 'Food stored successfully...!');        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Food  $food
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $food = Food::find($id);
        
        if (is_null($food)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($food, 'Food item successfully Retrieved...!'); 
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Food  $food
     * @return \Illuminate\Http\Response
     */
    public function edit(Food $food)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Food  $food
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|between:2,100',
            'food_type' => 'string',
            'description' => 'string',
            'nuetritional_info' => 'json',
            'image_url' => 'mimes:jpeg,jpg,png|max:2048',
            'visitor_count' => 'numeric',
            'meta_data' => 'json',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
      
        $food = Food::find($id);

        if (is_null($food)) {
            return $this->sendError('Empty', [], 404);
        }

        $input = $request->all();
        $date = currentDate(); //for unique naming of project folder
        Log::info("upload file starting");

        //Image 1 store      
        if ($image = $request->file('image_url')) {
            if(Storage::exists($food->image_url)){
                Storage::delete($food->image_url);
            }

            Log::info("inside upload image_url");
            
            $image_url = $date . "." . $image->getClientOriginalExtension();

            $path = $request->file('image_url')->store(config('constants.upload_path.food').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
            
        $food->update($input);

        }
        return $this->sendResponse($food, 'Food item updated successfully...!');        
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Food  $food
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $food = Food::find($id);

        if (is_null($food)) {
            return $this->sendError('Empty', [], 404);
        }

        if(Storage::exists($food->icon)){
            Storage::delete($food->icon);
        }

        $food->delete();

        return $this->sendResponse($food, 'Food item deleted successfully...!');  
    }
}
