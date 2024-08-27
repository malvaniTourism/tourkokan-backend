<?php

namespace App\Console\Commands;

use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GenerateOfflineRoute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:routes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $sites = Site::select(['id', 'name'])->where('parent_id', 1)->get();
        foreach ($sites as $key => $value) {

            $routes = RouteStops::where('site_id', $value->id)
                ->orderBy('route_id')
                ->get();

            // Group routes by route id
            $groupedRoutes = $routes->groupBy('route_id');

            // Get the route_ids of the filtered routes
            $routeIds = $groupedRoutes->keys()->toArray();

            $routes = Route::with([
                'routeStops:id,serial_no,route_id,site_id,arr_time,dept_time,total_time,delayed_time,distance',
                'routeStops.site:id,name,mr_name',
                'routeStops.site.categories:id,name,icon',
                'sourcePlace:id,name,mr_name',
                'sourcePlace.categories:id,name,icon',
                'destinationPlace:id,name,mr_name',
                'destinationPlace.categories:id,name,icon',
                'busType:id,type,logo,meta_data'
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
                DB::raw('(SELECT MAX(distance) FROM route_stops WHERE route_id = routes.id) AS distance')
            )
                ->whereIn('id', $routeIds)
                ->get()
                ->toJson();

            // Save the JSON data to a file
            $filePath = 'routes/' . $value->name . '.json';
            Storage::put($filePath, $routes);
        }
        return 0;
    }
}
