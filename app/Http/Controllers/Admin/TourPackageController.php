<?php

namespace App\Http\Controllers\Admin;

use App\Models\TourPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Validator;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BaseController as BaseController;

class TourPackageController extends BaseController
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
        $tourPackage = TourPackage::paginate(10);

        return $this->sendResponse($tourPackage, 'Tour Package successfully Retrieved...!'); 
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
        // $data = json_decode($request->social_media);
        // print_r(json_encode($data));exit;
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|nullable|numeric|exists:users,id',
            'title' => 'required|string',
            'tag_line' => 'string',
            'description' => 'required|string',
            'image_url' => 'required|mimes:jpeg,jpg,png|max:2048',
            'duration' => 'json',
            'dates' => 'json',
            'price' => 'json',
            'rules' => 'json',
            'ambience' => 'json',
            'includes' => 'json',
            'itenarary' => 'json',
            'contact_details' => 'json',
            'social_media' => 'json'
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

            $path = $request->file('image_url')->store(config('constants.upload_path.tourpackage').$request->name);

            $input['image_url'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['image_url']);
        }

        $tourPackage = TourPackage::create($input);

        return $this->sendResponse($tourPackage, 'Tour Package stored successfully...!');   
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\TourPackage  $tourPackage
     * @return \Illuminate\Http\Response
     */
    public function show(TourPackage $tourPackage)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\TourPackage  $tourPackage
     * @return \Illuminate\Http\Response
     */
    public function edit(TourPackage $tourPackage)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\TourPackage  $tourPackage
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, TourPackage $tourPackage)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\TourPackage  $tourPackage
     * @return \Illuminate\Http\Response
     */
    public function destroy(TourPackage $tourPackage)
    {
        //
    }
}
