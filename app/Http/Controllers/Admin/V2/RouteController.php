<?php

namespace App\Http\Controllers\Admin\V2;

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
    public function routes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_place_id' => 'nullable|required_with:destination_place_id|exists:sites,id',
            'destination_place_id' => 'nullable|required_with:source_place_id|exists:sites,id',
            'search' => 'nullable|string|alpha|max:255',
            'apitype' => 'required|string|max:255|in:list,dropdown',
            'with_stops' => 'sometimes|required|boolean:true,false',
            'per_page' => 'nullable|integer|max:30|min:1'
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

        $with = [
            'sourcePlace:id,name,mr_name',
            'sourcePlace.categories:id,name,icon',
            'destinationPlace:id,name,mr_name',
            'destinationPlace.categories:id,name,icon',
            'busType:id,type,logo,meta_data'
        ];

        if ($request->with_stops) {
            $additionalWith = [
                'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time,distance',
                'routeStops.site:id,name,mr_name',
                'routeStops.site.categories:id,name,icon',
            ];

            $with = array_merge($with, $additionalWith);
        }

        $routes = Route::with($with)->select(
            'id',
            'source_place_id',
            'destination_place_id',
            'bus_type_id',
            'name',
            'start_time',
            'end_time',
            'total_time',
            'delayed_time',
            DB::raw('(SELECT MAX(distance) FROM route_stops WHERE route_id = routes.id) AS distance')
        );

        if ($request->has('search')) {
            $search = $request->input('search');
            $routes = $routes->where('name', 'like', $search . '%');
        }

        if ($request->source_place_id && $request->destination_place_id) {
            $routes->whereIn('id', $routeIds);
        }

        $routes = $routes->select(isValidReturn(config('grid.listRoutes.' . $request->apitype), 'columns', '*'))
            ->paginateSafe();

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

    // public function routes(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'source_place_id' => 'nullable|required_with:destination_place_id|exists:places,id',
    //         'destination_place_id' => 'nullable|required_with:source_place_id|exists:places,id',
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->sendError($validator->errors(), '', 200);
    //     }

    //     $routeIds = Route::whereHas('routeStops', function ($query) use ($request) {
    //         $query->when($request->has('source_place_id') && $request->has('destination_place_id'), function ($subquery) use ($request) {
    //             $subquery->where('place_id', $request->source_place_id)
    //                 ->whereBetween('serial_no', [
    //                     DB::raw("(SELECT MIN(serial_no) FROM route_stops WHERE route_id = routes.id AND place_id IN ($request->source_place_id, $request->destination_place_id))"),
    //                     DB::raw("(SELECT MAX(serial_no) FROM route_stops WHERE route_id = routes.id AND place_id IN ($request->source_place_id, $request->destination_place_id))"),
    //                 ]);
    //         });
    //     })->pluck('id');

    //     $routes = Route::with([
    //         'routeStops:id,serial_no,route_id,place_id,arr_time,dept_time,total_time,delayed_time',
    //         'routeStops.place:id,name,place_category_id',
    //         'routeStops.place.placeCategory:id,name,icon',
    //         'sourcePlace:id,name,place_category_id',
    //         'sourcePlace.placeCategory:id,name,icon',
    //         'destinationPlace:id,name,place_category_id',
    //         'destinationPlace.placeCategory:id,name,icon',
    //         'busType:id,type,logo,meta_data'
    //     ])->select('id', 'source_place_id', 'destination_place_id', 'bus_type_id', 'name', 'start_time', 'end_time', 'total_time', 'delayed_time');

    //     $routes->when($request->has('source_place_id') && $request->has('destination_place_id'), function ($query) use ($routeIds) {
    //         $query->whereIn('id', $routeIds);
    //     });

    //     $routes = $routes->paginate(5);

    //     #need to test on both query for performance

    //     // $data = $request->validate([
    //     //     'source_place_id' => 'exists:places,id|required_with:destination_place_id',
    //     //     'destination_place_id' => 'exists:places,id|required_with:source_place_id',
    //     // ]);


    //     // $routes = Route::with([
    //     //     'routeStops:id,serial_no,route_id,place_id,arr_time,dept_time,total_time,delayed_time',
    //     //     'routeStops.place:id,name,place_category_id',
    //     //     'routeStops.place.placeCategory:id,name,icon',
    //     //     'sourcePlace:id,name,place_category_id',
    //     //     'sourcePlace.placeCategory:id,name,icon',
    //     //     'destinationPlace:id,name,place_category_id',
    //     //     'destinationPlace.placeCategory:id,name,icon',
    //     //     'busType:id,type,logo'
    //     // ])->select('id', 'source_place_id', 'destination_place_id', 'bus_type_id', 'name', 'start_time', 'end_time', 'total_time', 'delayed_time')
    //     //     ->whereHas('routeStops', function ($query) use ($request) {
    //     //         $sourcePlaceId = $request->source_place_id;
    //     //         $destinationPlaceId = $request->destination_place_id;

    //     //         $query->where('place_id', $sourcePlaceId)
    //     //             ->whereExists(function ($subquery) use ($sourcePlaceId, $destinationPlaceId) {
    //     //                 $subquery->select(DB::raw(1))
    //     //                     ->from('route_stops')
    //     //                     ->where('route_id', DB::raw('routes.id'))
    //     //                     ->where('place_id', $destinationPlaceId)
    //     //                     ->where('serial_no', '>', function ($subsubquery) use ($sourcePlaceId) {
    //     //                         $subsubquery->select('serial_no')
    //     //                             ->from('route_stops')
    //     //                             ->where('route_id', DB::raw('routes.id'))
    //     //                             ->where('place_id', $sourcePlaceId);
    //     //                     });
    //     //             });
    //     //     })
    //     //     ->paginate(5);

    //     return $this->sendResponse($routes, 'available routes successfully Retrieved...!');
    // }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function addRoute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'route_no'               => 'nullable|integer|min:1|unique:routes,route_no',
            'name'                   => 'required|string|max:255',
            'source_place_id'        => 'required|integer|exists:sites,id',
            'destination_place_id'   => 'required|integer|exists:sites,id|different:source_place_id',
            'bus_type_id'            => 'required|integer|exists:bus_types,id',
            'start_time'             => 'required|date_format:H:i:s',
            'end_time'               => 'required|date_format:H:i:s',
            'total_time'             => 'nullable|date_format:H:i:s',
            'delayed_time'           => 'nullable|date_format:H:i:s',
            'distance'               => 'nullable|numeric|min:0',
            'working_days'           => 'nullable|string|max:255',
            'description'            => 'nullable|string',
            'meta_data'              => 'nullable|array',
            'status'                 => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $routeNo = $request->filled('route_no')
            ? $request->route_no
            : (Route::max('route_no') ?? 0) + 1;

        $route = Route::create(array_merge(
            $request->only([
                'name', 'source_place_id', 'destination_place_id',
                'bus_type_id', 'start_time', 'end_time', 'total_time',
                'delayed_time', 'distance', 'working_days', 'description', 'meta_data',
            ]),
            [
                'route_no' => $routeNo,
                'status'   => $request->boolean('status', false),
            ]
        ));

        return $this->sendResponse($route, 'Route added successfully');
    }

    public function deleteRoute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:routes,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        Route::findOrFail($request->id)->delete();

        return $this->sendResponse([], 'Route deleted successfully');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Route  $route
     * @return \Illuminate\Http\Response
     */
    public function routeDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:routes,id'
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $route = Route::with([
            'sourcePlace:id,name,mr_name',
            'sourcePlace.categories:id,name,icon',
            'destinationPlace:id,name,mr_name',
            'destinationPlace.categories:id,name,icon',
            'busType:id,type,logo,meta_data',
            'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time,distance',
            'routeStops.site:id,name,mr_name',
            'routeStops.site.categories:id,name,icon',
        ])->find($request->id);


        return $this->sendResponse($route, 'route successfully Retrieved...!');
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
     * Update the specified Route by ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function routesUpdate(Request $request)
    {
        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:routes,id',
            'source_place_id' => 'sometimes|required|integer|exists:sites,id',
            'destination_place_id' => 'sometimes|required|integer|exists:sites,id',
            'bus_type_id' => 'sometimes|required|integer|exists:bus_types,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'distance' => 'sometimes|required|regex:/^\d+(\.\d{1,2})?$/',
            'meta_data' => 'sometimes|nullable|array',
            'start_time' => 'sometimes|required|date_format:H:i:s',
            'end_time' => 'sometimes|required|date_format:H:i:s',
            'delayed_time' => 'sometimes|nullable|date_format:H:i:s',
        ]);

        // Return validation errors, if any
        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        // Update the Route with validated data
        $route = Route::where('id', $request->id)->update(array_filter($request->all()));

        return $this->sendResponse(true, 'Route updated successfully...!');
    }

    public function massRouteStopsUpdate(Request $request)
    {
        // Validate the incoming request
        $validator = Validator::make($request->all(), [
            'route_stops' => 'required|array|min:1',
            'route_stops.*.id' => 'required|integer|exists:route_stops,id',
            'route_stops.*.serial_no' => 'required|integer|min:1',
            'route_stops.*.route_id' => 'required|integer|exists:routes,id',
            'route_stops.*.site_id' => 'required|integer|exists:sites,id',
            'route_stops.*.arr_time' => 'required|date_format:H:i:s',
            'route_stops.*.dept_time' => 'required|date_format:H:i:s',
            'route_stops.*.total_time' => 'required|date_format:H:i:s',
            'route_stops.*.delayed_time' => 'required|date_format:H:i:s',
            'route_stops.*.distance' => 'nullable|numeric|min:0',
        ]);

        // Return validation errors, if any
        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        // Prepare the data for upsert
        $routeStopsData = $request->route_stops;

        RouteStops::upsert(
            $routeStopsData, // Data to upsert
            ['id'],          // Unique key to check for existing rows
            [                // Columns to update if the row exists
                'serial_no',
                'route_id',
                'site_id',
                'arr_time',
                'dept_time',
                'total_time',
                'delayed_time',
                'distance',
            ]
        );

        return $this->sendResponse(true, 'Route stops updated successfully...!');
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
