<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Rating;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class RatingController extends BaseController
{
    // /**
    //  * Create a new AuthController instance.
    //  *
    //  * @return void
    //  */
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
        $ratings = Rating::with(['user' =>  function ($query) {
                                $query->select('id', 'name', 'email', 'profile_picture');
                            }])
                         ->paginate(10);

        return $this->sendResponse($ratings, 'All Ratings successfully Retrieved...!');   
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
            'user_id' => 'required|numeric|exists:users,id',
            'rate' => 'required|numeric|in:1,2,3,4,5',
            'rateable_type' => 'required|string',
            'rateable_id' => 'required|numeric',
            'status' => 'boolean',
        ]);

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }
        // make sure that ratings could not rpeate on same project producty city or any propery7
        // $rating = Rating::where([['user_id', $request->user_id], 
        //                         ['rateable_type',  $request->rateable_type],
        //                         ['rateable_id', $request->rateable_id]])
        //                 ->first();
       

        // if (!empty($rating)) {
        //     return $this->sendError("Already rating is given", 'Already rated', 400);       
        // }

        $data = getData($request->rateable_id, $request->rateable_type);

        if (!$data) {
            return $this->sendError($request->rateable_type.' Not Exist..!', '', 400);       
        }

        $rating = new Rating;
        
        $rating->user_id = $request->user_id;
        
        $rating->rating = $request->rate;

        $rating->status = $request->status;

        $rating->rateable()->associate($data);

        $rating = Rating::create(json_decode($rating, true));       

        return $this->sendResponse($rating, 'Rating added successfully...!');    
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Rating  $rating
     * @return \Illuminate\Http\Response
     */
    public function show(Rating $rating)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Rating  $rating
     * @return \Illuminate\Http\Response
     */
    public function edit(Rating $rating)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Rating  $rating
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'nullable|numeric|in:1,2,3,4,5',
            'status' => 'nullable|boolean',
        ]);            

        if($validator->fails()){
            return $this->sendError($validator->errors(), '', 200);       
        }

        $rate = Rating::find($id);

        if (is_null($rate)) {
            return $this->sendError('Empty', [], 404);
        }

        $rate->update($request->all());

        return $this->sendResponse($rate, 'rate updated successfully...!');          
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Rating  $rating
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $rate = Rating::find($id);

        if (is_null($rate)) {
            return $this->sendError('Empty', [], 404);
        }

        $rate->delete($id);

        return $this->sendResponse($rate, 'Rating deleted successfully...!');   
    }
}
