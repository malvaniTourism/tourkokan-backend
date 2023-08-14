<?php

namespace App\Http\Controllers\API\V1;

use App\Models\Place;
use Illuminate\Http\Request;

use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Validator;

class PlaceController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = auth()->user();

        $places = Place::withCount(['photos', 'comments'])
            ->with('photos', 'city:id,name,image_url')
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('places.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', Place::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->paginate(10);
        return $this->sendResponse($places, 'Places successfully Retrieved...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Place  $place
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //write visitors count update here by one
        $place = Place::withCount(['photos', 'comments'])
            ->with(['photos', 'city', 'comments', 'comments.comments', 'comments.users', 'comments.comments.users'])
            ->find($id);

        // $city = City::withCount(['projects', 'places', 'photos', 'comments'])
        //             ->with(['comments', 'comments.comments', 'comments.users', 'comments.comments.users', 'places', 'photos'])
        //             ->latest()
        //             ->limit(10)
        //             ->find($id);

        if (is_null($place)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($place, 'Place successfully Retrieved...!');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function searchPlace(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|alpha|max:255',
            'type' => 'sometimes|nullable|string|max:255|in:bus',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $places = Place::withCount(['photos', 'comments'])
            ->with(['photos', 'city:id,name,image_url', 'placeCategory:id,name,icon']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $places = $places->where('name', 'like', '%' . $search . '%');
        }

        if ($request->has('type') && $request->input('type') == 'bus') {
            $places = $places->whereHas('placeCategory', function ($query) {
                $query->whereIn('name', ['Bus Stop', 'Bus Depo']);
            });
        }
        $places = $places->select('id', 'name', 'city_id', 'parent_id', 'place_category_id', 'image_url', 'bg_image_url', 'visitors_count')
            ->paginate();

        return $this->sendResponse($places, 'Stops successfully Retrieved...!');
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function stops()
    {
        $places = Place::withCount(['photos', 'comments'])
            ->with(['photos', 'city:id,name,image_url', 'placeCategory:id,name,icon'])
            ->whereHas('placeCategory', function ($query) {
                $query->whereIn('name', ['Bus Stop', 'Bus Depo']);
            })
            ->select('id', 'name', 'city_id', 'parent_id', 'place_category_id', 'image_url', 'bg_image_url', 'visitors_count')
            ->paginate(10);

        return $this->sendResponse($places, 'Stops successfully Retrieved...!');
    }
}
