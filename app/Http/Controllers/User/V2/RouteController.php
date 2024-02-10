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
    public function listroutes()
    {
        $routes = Route::withCount(['routeStops'])
            ->with([
                'sourcePlace:id,name,category_id',
                'sourcePlace.category:id,name,icon',
                'destinationPlace:id,name,category_id',
                'destinationPlace.category:id,name,icon'
            ])
            ->select('id', 'source_place_id', 'destination_place_id', 'name', 'start_time', 'end_time', 'total_time', 'delayed_time')
            ->paginate();

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
            'source_place_id' => 'nullable|required_with:destination_place_id|exists:sites,id',
            'destination_place_id' => 'nullable|required_with:source_place_id|exists:sites,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        // if ($request->source_place_id) {
        //    return 5;
        // }
        // return $request->all();

        // $routeIds = Route::whereHas('routeStops', function ($query) use ($request) {
        //     if ($request->source_place_id && $request->destination_place_id) {
        //         $query->where('site_id', $request->source_place_id)
        //             ->whereBetween('serial_no', [
        //                 DB::raw("(SELECT MIN(serial_no) FROM route_stops WHERE route_id = routes.id AND site_id IN ($request->source_place_id, $request->destination_place_id))"),
        //                 DB::raw("(SELECT MAX(serial_no) FROM route_stops WHERE route_id = routes.id AND site_id IN ($request->source_place_id, $request->destination_place_id))"),
        //             ]);
        //     }
        // })->pluck('id');

        // $where = array(
        //     'source_place_id' => $request->source_place_id,
        //     'destination_place_id' => $request->destination_place_id,
        // );

        // // $routeIds = Route::with('routeStops')->where($where)->get();

        // $whereRouteStops = array(
        //     'site_id' => $request->source_place_id,
        //     'site_id' => $request->destination_place_id
        // );
        // $routeIds = RouteStops::where($whereRouteStops)->get();

        $routes = RouteStops::where('site_id', $request->source_place_id)
            ->orWhere('site_id', $request->destination_place_id)
            ->orderBy('route_id')
            ->get();

        // Group routes by route id
        $groupedRoutes = $routes->groupBy('route_id');

        // Filter routes with both source and destination place ids
        $validRoutes = $groupedRoutes->filter(function ($stops) use ($request) {
            $sourceStop = $stops->firstWhere('site_id', $request->source_place_id);
            $destinationStop = $stops->firstWhere('site_id', $request->destination_place_id);

            // Check if both source and destination stops exist in the route
            if ($sourceStop && $destinationStop) {
                // Ensure source stop's serial number is less than destination stop's serial number
                return $sourceStop->serial_no < $destinationStop->serial_no;
            }

            return false; // Return false if any of the stops is missing
        });

        // Get the route_ids of the filtered routes
        $routeIds = $validRoutes->keys()->toArray();

        // $routeIds = Route::whereHas('routeStops', function ($query) use ($request) {
        //     if ($request->source_place_id && $request->destination_place_id) {
        //         $query->whereIn('site_id', [$request->source_place_id, $request->destination_place_id])
        //             ->where(function ($q) use ($request) {
        //                 $q->where('serial_no', '>', function ($subQuery) use ($request) {
        //                     $subQuery->select(DB::raw('MIN(serial_no)'))
        //                         ->from('route_stops')
        //                         ->whereColumn('route_stops.route_id', 'routes.id')
        //                         ->whereIn('site_id', [$request->source_place_id, $request->destination_place_id]);
        //                 })->where('serial_no', '<', function ($subQuery) use ($request) {
        //                     $subQuery->select(DB::raw('MAX(serial_no)'))
        //                         ->from('route_stops')
        //                         ->whereColumn('route_stops.route_id', 'routes.id')
        //                         ->whereIn('site_id', [$request->source_place_id, $request->destination_place_id]);
        //                 });
        //             });
        //     }
        // })->pluck('id');

        $routes = Route::with([
            'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time',
            'routeStops.site:id,name,category_id',
            'routeStops.site.category:id,name,icon',
            'sourcePlace:id,name,category_id',
            'sourcePlace.category:id,name,icon',
            'destinationPlace:id,name,category_id',
            'destinationPlace.category:id,name,icon',
            'busType:id,type,logo,meta_data'
        ])->select('id', 'source_place_id', 'destination_place_id', 'bus_type_id', 'name', 'start_time', 'end_time', 'total_time', 'delayed_time');

        if ($request->source_place_id && $request->destination_place_id) {
            $routes->whereIn('id', $routeIds);
        }

        $routes = $routes->paginate(10);

        #need to test on both query for performance

        // $data = $request->validate([
        //     'source_place_id' => 'exists:places,id|required_with:destination_place_id',
        //     'destination_place_id' => 'exists:places,id|required_with:source_place_id',
        // ]);


        // $routes = Route::with([
        //     'routeStops:id,serial_no,route_id,place_id,arr_time,dept_time,total_time,delayed_time',
        //     'routeStops.place:id,name,place_category_id',
        //     'routeStops.place.category:id,name,icon',
        //     'sourcePlace:id,name,place_category_id',
        //     'sourcePlace.category:id,name,icon',
        //     'destinationPlace:id,name,place_category_id',
        //     'destinationPlace.category:id,name,icon',
        //     'busType:id,type,logo'
        // ])->select('id', 'source_place_id', 'destination_place_id', 'bus_type_id', 'name', 'start_time', 'end_time', 'total_time', 'delayed_time')
        //     ->whereHas('routeStops', function ($query) use ($request) {
        //         $sourcePlaceId = $request->source_place_id;
        //         $destinationPlaceId = $request->destination_place_id;

        //         $query->where('place_id', $sourcePlaceId)
        //             ->whereExists(function ($subquery) use ($sourcePlaceId, $destinationPlaceId) {
        //                 $subquery->select(DB::raw(1))
        //                     ->from('route_stops')
        //                     ->where('route_id', DB::raw('routes.id'))
        //                     ->where('place_id', $destinationPlaceId)
        //                     ->where('serial_no', '>', function ($subsubquery) use ($sourcePlaceId) {
        //                         $subsubquery->select('serial_no')
        //                             ->from('route_stops')
        //                             ->where('route_id', DB::raw('routes.id'))
        //                             ->where('place_id', $sourcePlaceId);
        //                     });
        //             });
        //     })
        //     ->paginate(5);

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
