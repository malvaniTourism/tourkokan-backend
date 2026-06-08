<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Favourite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FavouriteController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $favourite =  Favourite::where('user_id', auth()->id())
            ->paginate(10);

        return $this->sendResponse($favourite, 'Favourites successfully Retrieved...!');
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
    public function addDeleteFavourite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'favouritable_type' => 'required|string',
            'favouritable_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $data = getData($request->favouritable_id, $request->favouritable_type);

        if (!$data) {
            return $this->sendError($request->favouritable_type . ' Not Exist..!', '', 400);
        }

        $favouritableType = "App\\Models\\" . $request->favouritable_type;

        $favourite = $data->favourites()
            ->where([
                'user_id' => auth()->id()
            ])
            ->whereHasMorph('favouritable', $favouritableType, function ($subquery) use ($request) {
                $subquery->where('id', $request->favouritable_id);
            })->first();

        if ($favourite) {
            $favourite->delete();

            $request->attributes->set('log_entity_type', strtolower($request->favouritable_type));
            $request->attributes->set('log_entity_id',   (int) $request->favouritable_id);
            $request->attributes->set('log_meta_data', ['action' => 'remove']);

            return $this->sendResponse(null, 'Favourite deleted successfully...!');
        } else {
            $favourite = [
                'user_id' => auth()->id()
            ];

            $favourite = $data->favourites()->create(array_filter($favourite));
        }

        $request->attributes->set('log_entity_type', strtolower($request->favouritable_type));
        $request->attributes->set('log_entity_id',   (int) $request->favouritable_id);
        $request->attributes->set('log_meta_data', ['action' => 'add']);

        return $this->sendResponse($favourite, 'Favourite created successfully...!');
    }
}
