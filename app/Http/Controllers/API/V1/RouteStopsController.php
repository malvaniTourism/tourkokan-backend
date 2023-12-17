<?php

namespace App\Http\Controllers\API\V1;

use App\Models\RouteStops;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;

class RouteStopsController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'source_place_id' => 'required|exists:sites,id|required_with:destination_place_id',
            'destination_place_id' => 'required|exists:sites,id|required_with:source_place_id',
        ]);

        $places = RouteStops::with(['sites'])
            ->whereIn('site_id', $data)
            // ->when($data['search'], function ($query, $search) {
            //     $query->where('name', 'like', '%' . $search . '%');
            // })
            // ->when($data['type'] == 'bus', function ($query) {
            //     $query->whereHas('placeCategory', function ($query) {
            //         $query->whereIn('name', ['Bus Stop', 'Bus Depo']);
            //     });
            // })
            ->select('*')
            ->get();

        return $this->sendResponse($places, 'avaible routes successfully Retrieved...!');
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
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\RouteStops  $routeStops
     * @return \Illuminate\Http\Response
     */
    public function show(RouteStops $routeStops)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\RouteStops  $routeStops
     * @return \Illuminate\Http\Response
     */
    public function edit(RouteStops $routeStops)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\RouteStops  $routeStops
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, RouteStops $routeStops)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\RouteStops  $routeStops
     * @return \Illuminate\Http\Response
     */
    public function destroy(RouteStops $routeStops)
    {
        //
    }
}
