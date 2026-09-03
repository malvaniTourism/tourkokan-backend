<?php

namespace App\Http\Controllers\User\V2;

use App\Models\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\RouteStops;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RouteController extends BaseController
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
    public function listroutes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'sometimes|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $routes = Route::withCount(['routeStops'])
            ->with([
                'sourcePlace:id,name',
                'sourcePlace.categories:id,name,icon',
                'destinationPlace:id,name',
                'destinationPlace.categories:id,name,icon'
            ])
            ->select(
                'id',
                'source_place_id',
                'destination_place_id',
                'name',
                'start_time',
                'end_time',
                'total_time',
                'delayed_time',
                DB::raw('(SELECT MAX(distance) FROM route_stops WHERE route_id = routes.id) AS distance')
            )
            // A timetable reads in departure order; without this the list came back in
            // whatever order the engine happened to return.
            ->orderByRaw('routes.start_time IS NULL, routes.start_time ASC, routes.id ASC')
            ->paginateSafe();

        return $this->sendResponse($routes, 'Routes successfully Retrieved...!');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function routes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page'             => 'sometimes|integer|min:1|max:30',
            'source_place_id'      => 'nullable|required_with:destination_place_id|exists:sites,id',
            'destination_place_id' => 'nullable|required_with:source_place_id|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $query = Route::with([
            'sourcePlace:id,name,mr_name',
            'sourcePlace.categories:id,name,icon',
            'destinationPlace:id,name,mr_name',
            'destinationPlace.categories:id,name,icon',
            'busType:id,type,logo,meta_data',
        ])->select(
            'id',
            'source_place_id',
            'destination_place_id',
            'bus_type_id',
            'name',
            'start_time',
            'end_time',
            'total_time',
            'delayed_time',
            DB::raw('ROUND((SELECT MAX(distance) FROM route_stops WHERE route_id = routes.id), 2) AS distance'),
            DB::raw('(SELECT COUNT(*) FROM route_stops WHERE route_id = routes.id) AS route_stops_count')
        );

        $sourceId = $request->source_place_id;

        if ($request->filled('source_place_id') && $request->filled('destination_place_id')) {
            // Self-join on route_stops: find routes where source serial_no < destination serial_no
            $routeIds = DB::table('route_stops as rs1')
                ->join('route_stops as rs2', 'rs1.route_id', '=', 'rs2.route_id')
                ->where('rs1.site_id', $request->source_place_id)
                ->where('rs2.site_id', $request->destination_place_id)
                ->whereColumn('rs1.serial_no', '<', 'rs2.serial_no')
                ->distinct()
                ->pluck('rs1.route_id');

            $query->whereIn('id', $routeIds);
        }

        // Departure is ordered from the stop the traveller actually boards at, not from the
        // route's origin. A bus that starts at 06:00 two districts away may reach this stop
        // after one that started at 08:00 nearby, so ordering on routes.start_time would show
        // a timetable in the wrong order for anyone boarding mid-route.
        if ($sourceId) {
            $query->addSelect(DB::raw(
                '(SELECT rs.dept_time FROM route_stops rs
                   WHERE rs.route_id = routes.id AND rs.site_id = ?
                   ORDER BY rs.serial_no LIMIT 1) AS source_dept_time'
            ))->addBinding([$sourceId], 'select');
        }

        // Stops with no recorded time sort last rather than leading the list.
        $query->orderByRaw(
            $sourceId
                ? 'source_dept_time IS NULL, source_dept_time ASC, routes.id ASC'
                : 'routes.start_time IS NULL, routes.start_time ASC, routes.id ASC'
        );

        $routes = $query->paginateSafe();

        $request->attributes->set('log_meta_data', [
            'source_id'      => $request->source_place_id ? (int) $request->source_place_id : null,
            'destination_id' => $request->destination_place_id ? (int) $request->destination_place_id : null,
            'results_count'  => $routes->total(),
        ]);

        return $this->sendResponse($routes, 'available routes successfully Retrieved...!');
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
     * @param  \App\Models\Route  $route
     * @return \Illuminate\Http\Response
     */
    public function show(Route $route)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Route  $route
     * @return \Illuminate\Http\Response
     */
    public function edit(Route $route)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Route  $route
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Route $route)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Route  $route
     * @return \Illuminate\Http\Response
     */
    public function destroy(Route $route)
    {
        //
    }
}
