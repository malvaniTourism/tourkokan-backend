<?php

namespace App\Http\Controllers\User\V2;

use App\Models\RouteStops;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Validator;

class RouteStopsController extends BaseController
{
    public function getRouteStops(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'route_id' => 'required|exists:routes,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors(), '', 200);
        }

        $stops = RouteStops::with([
            'site:id,name,mr_name',
            'site.categories:id,name,icon',
        ])
            ->where('route_id', $request->route_id)
            ->select('id', 'serial_no', 'route_id', 'site_id', 'arr_time', 'dept_time', 'total_time', 'delayed_time', 'distance')
            ->orderBy('serial_no')
            ->get();

        return $this->sendResponse($stops, 'Route stops successfully retrieved.');
    }
}
