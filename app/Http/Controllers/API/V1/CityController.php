<?php

namespace App\Http\Controllers\API\V1;

use App\Models\City;
use Illuminate\Http\Request;
use Validator;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CityController extends BaseController
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = auth()->user();

        $cities = City::withCount(['projects', 'places', 'photos', 'comments'])
            ->selectSub(function ($query) use ($user) {
                $query->selectRaw('CASE WHEN COUNT(*) > 0 THEN TRUE ELSE FALSE END')
                    ->from('favourites')
                    ->whereColumn('cities.id', 'favourites.favouritable_id')
                    ->where('favourites.favouritable_type', City::class)
                    ->where('favourites.user_id', $user->id);
            }, 'is_favorite')
            ->latest()
            ->paginate(10);

        return $this->sendResponse($cities, 'Cities successfully Retrieved...!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\City  $city
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $city   =   City::withCount(['projects', 'places', 'photos', 'comments'])
            ->withAvg("rateable", 'rate')
            ->with([
                'projects.category' => function ($query) {
                    $query->select('id', 'name')
                        ->limit(5);
                },
                'projects' => function ($query) {
                    $query->select('id', 'category_id', 'name', 'logo', 'city_id')
                        ->limit(5);
                },
                'projects.city' => function ($query) {
                    $query->select('id', 'name', 'image_url')
                        ->limit(5);
                },
                'places' => function ($query) {
                    $query->select('id', 'name', 'city_id', 'image_url')
                        ->limit(5);
                },
                'comments' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comments.comments' => function ($query) {
                    $query->select('id', 'parent_id', 'user_id', 'comment', 'commentable_type', 'commentable_id')
                        ->limit(5);
                },
                'comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'comments.comments.users' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_picture');
                },
                'photos'
            ])
            ->latest()
            ->limit(5)
            ->find($id);

        return $this->sendResponse($city, 'Cities successfully Retrieved...!');
    }

    /**
     * Display a listing of the city.
     *
     * @return \Illuminate\Http\Response
     */
    public function getAllcities($id)
    {
        $cities = City::withCount(['projects', 'places', 'photos', 'comments'])
            ->with(['projects', 'places', 'photos', 'comments'])
            ->whereId($id)
            ->first();

        if (is_null($cities)) {
            return $this->sendError('Empty', [], 404);
        }

        return $this->sendResponse($cities, 'Cities successfully Retrieved...!');
    }
}
