<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Address;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\BaseController as BaseController;

class AddressController extends BaseController
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
            'address1' => 'required|string',
            'address2' => 'nullable|string',
            'address3' => 'nullable|string',
            'block' => 'nullable|string',
            'landmark' => 'nullable|string',
            'type' => 'string',
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'district' => 'nullable|string',
            'city' => 'nullable|string',
            'zip' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'addressable_type' => 'required|string',
            'addressable_id' => 'required|numeric',
        ]);            

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        // $data = json_encode(DB::table($request->addressable_type)->find($request->addressable_id));//getData($request->addressable_id, $request->addressable_type);
        $data = getData($request->addressable_id, $request->addressable_type);

        if (!$data) {
            return $this->sendError($request->addressable_type.' Not Exist..!', '', 400);       
        }

        $address = new Address;

        $address->address1 = $request->get('address1');

        $address->address2 = $request->get('address2');

        $address->address3 = $request->get('address3');

        $address->block = $request->get('block');

        $address->type = $request->get('type');
        
        $address->landmark = $request->get('landmark');
        
        $address->country = $request->get('country');

        $address->state = $request->get('state');

        $address->district = $request->get('district');

        $address->city = $request->get('city');

        $address->zip = $request->get('zip');

        $address->latitude = $request->get('latitude');

        $address->longitude = $request->get('longitude');

        $address->addressable()->associate($data);

        // $address = $address->save();       
        $address = Address::create(json_decode($address, true));       

        return $this->sendResponse($address, 'Address added successfully...!');    
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function show(Address $address)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function edit(Address $address)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'address1' => 'nullable|string',
            'address2' => 'nullable|string',
            'address3' => 'nullable|string',
            'block' => 'nullable|string',
            'landmark' => 'nullable|string',
            'type' => 'string',
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'district' => 'nullable|string',
            'city' => 'nullable|string',
            'zip' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
        ]);    

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $address = Address::find($id);

        if (is_null($address)) {
            return $this->sendError('Empty', [], 404);
        }

        $address->update($request->all());

        return $this->sendResponse($address, 'Your '.$request->type.' address updated successfully...!');   
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Address  $address
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $address = Address::find($id);

        if (is_null($address)) {
            return $this->sendError('Empty', [], 404);
        }

        $address->delete($id);

        return $this->sendResponse($address, 'Address deleted successfully...!');  
    }
}
