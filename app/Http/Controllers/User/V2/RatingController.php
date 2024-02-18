<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Rating;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Validator;

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
            ->where('user_id', config('user_id'))
            ->paginate(10);

        return $this->sendResponse($ratings, 'All Ratings successfully Retrieved...!');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addUpdateRating(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'required|numeric|between:0,5',
            'rateable_type' => 'required|string',
            'rateable_id' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $data = getData($request->rateable_id, $request->rateable_type);

        if (!$data) {
            return $this->sendError($request->rateable_type . ' Not Exist..!', '', 400);
        }

        $rateableType = "App\\Models\\" . $request->rateable_type;

        $existingRating = $data->rating()->where('user_id', config('user_id'))
            ->whereHasMorph('rateable', $rateableType, function ($subquery) use ($request) {
                $subquery->where('id', $request->rateable_id);
            })->first();

        if ($existingRating) {
            $rating = $existingRating->update(['rate' => $request->rate]);
        } else {
            $rating = [
                'user_id' =>  config('user_id'),
                'rate' => $request->rate
            ];

            $data->rating()->create($rating);
        }

        return $this->sendResponse($rating, 'Rating added successfully...!');
    }
}
