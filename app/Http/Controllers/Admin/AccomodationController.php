<?php

namespace App\Http\Controllers\Admin;

use Validator;
use App\Models\Accomodation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class AccomodationController extends BaseController
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
        $accomodation = Accomodation::paginate(10);

        return $this->sendResponse($accomodation, 'Accomodation successfully Retrieved...!');  
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
            'accomodation_category_id' => 'required|nullable|numeric|exists:accomodation_categories,id',
            'room_type' => 'required|string',
            'description' => 'required|string',
            'rules' => 'nullable|string',
            'price' => 'nullable|string',
            'meta_data' => 'nullable|string',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
      
        $accomodation = Accomodation::create($request->all());

        return $this->sendResponse($accomodation, 'Accomodation added successfully...!');       
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Accomodation  $accomodation
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $accomodation = Accomodation::find($id);
        
        if (is_null($accomodation)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($accomodation, 'Accomodation successfully Retrieved...!'); 
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Accomodation  $accomodation
     * @return \Illuminate\Http\Response
     */
    public function edit(Accomodation $accomodation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Accomodation  $accomodation
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'nullable|numeric|exists:projects,id',
            'accomodation_category_id' => 'nullable|numeric|exists:accomodation_categories,id',
            'room_type' => 'nullable|string',
            'description' => 'nullable|string',
            'rules' => 'nullable|string',
            'price' => 'nullable|string',
            'meta_data' => 'nullable|string',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $role = Roles::find($id);

        if (is_null($role)) {
            return $this->sendError('Empty', [], 404);
        }

        $role->update($request->all());

        return $this->sendResponse($role, 'Roles updated successfully...!');  
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Accomodation  $accomodation
     * @return \Illuminate\Http\Response
     */
    public function destroy(Accomodation $accomodation)
    {
        $role = Roles::find($id);

        if (is_null($role)) {
            return $this->sendError('Empty', [], 404);
        }

        $role->delete($request->all());

        return $this->sendResponse($role, 'Roles deleted successfully...!');
    }
}
