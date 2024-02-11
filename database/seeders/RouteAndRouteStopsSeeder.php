<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Imports\RouteAndRouteStopsImport;
use Maatwebsite\Excel\Facades\Excel;

class RouteAndRouteStopsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/AllRoutesWithStopsCSV.csv';
		Excel::import(new RouteAndRouteStopsImport, $path);
        // $faker = \Faker\Factory::create();

        // $string = '[{"Format":"I25","Content":"172284201241"}]';

        // $routes = Route::all();

        // foreach ($routes as $key => $value) {
        //     for ($i = 0; $i < 5; $i++) {
        //         $site_id = 0;

        //         $arr_time = new DateTime($faker->dateTimeThisCentury()->format('h:i:s A'));
        //         $dept_time = new DateTime($faker->dateTimeThisCentury($arr_time)->format('h:i:s A'));

        //         if ($i == 0) {
        //             $site_id = $value->source_place_id;
        //         }

        //         if ($i == 4) {
        //             $site_id = $value->destination_place_id;
        //         }

        //         if ($i != 0 && $i != 4) {
        //             $source_place =  Site::all()->random();

        //             $site_id = $source_place->id;
        //         }

        //         $exist = RouteStops::where("route_id", $value->id)
        //             ->where("site_id", $site_id)
        //             ->first();

        //         if (!$exist) {
        //             $start_time = new DateTime($value['start_time']);

        //             $data = array(
        //                 'serial_no' => $i + 1,
        //                 'route_id' => $value['id'],
        //                 'site_id' => $site_id,
        //                 'meta_data' => $string,
        //                 'arr_time' => $arr_time,
        //                 'dept_time' => $dept_time,
        //                 'total_time' => $dept_time->diff($start_time)->format('%H:%i:%s'),
        //                 'delayed_time' => $faker->time()
        //             );

        //             RouteStops::create($data);
        //         }
        //     }
        // }
    }
}
