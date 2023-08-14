<?php

namespace App\Http\Controllers\Admin;

use App\Models\BusType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class BusTypeController extends BaseController
{
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
            'type' => 'required|string|unique:bus_types|between:2,100',
            'logo' => 'required|mimes:jpeg,jpg,png,webp|max:2048',
            'meta_data' => 'json',
        ]);

        if($validator->fails())
            return $this->sendError($validator->errors(), '', 200);       

        $input = $request->all();
        Log::info("upload file starting");
        //Image 1 store      
        if ($image = $request->file('logo')) {
            Log::info("inside upload logo");
            
            $logo = date('YmdHis') . "." . $image->getClientOriginalExtension();

            $path = $request->file('logo')->store(config('constants.upload_path.busType').'/'.$request->type);

            $input['logo'] = Storage::url($path);
            
            Log::info("FILE STORED".$input['logo']);
        }

        $busType = BusType::create($input);

        return $this->sendResponse($busType, 'Bus types added successfully...!');   
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\BusType  $busType
     * @return \Illuminate\Http\Response
     */
    public function show(BusType $busType)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\BusType  $busType
     * @return \Illuminate\Http\Response
     */
    public function edit(BusType $busType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\BusType  $busType
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, BusType $busType)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\BusType  $busType
     * @return \Illuminate\Http\Response
     */
    public function destroy(BusType $busType)
    {
        //
    }
}
