<?php

namespace Database\Seeders;

use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Carbon\Carbon;
use DateInterval;
use DateTime;
use Illuminate\Database\Seeder;

class RouteStopsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = \Faker\Factory::create();

        $string = '[{"Format":"I25","Content":"172284201241"}]';

        $routes = Route::all();

        foreach ($routes as $key => $value) {
            for ($i = 0; $i < 5; $i++) {
                $place_id = 0;

                $arr_time = new DateTime($faker->dateTimeThisCentury()->format('h:i:s A'));
                $dept_time = new DateTime($faker->dateTimeThisCentury($arr_time)->format('h:i:s A'));

                if ($i == 0) {
                    $place_id = $value->source_place_id;
                }

                if ($i == 4) {
                    $place_id = $value->destination_place_id;
                }

                if ($i != 0 && $i != 4) {
                    $source_place =  Site::all()->random();

                    $place_id = $source_place->id;
                }

                $exist = RouteStops::where("route_id", $value->id)
                    ->where("place_id", $place_id)
                    ->first();

                if (!$exist) {
                    $start_time = new DateTime($value['start_time']);

                    $data = array(
                        'serial_no' => $i + 1,
                        'route_id' => $value['id'],
                        'place_id' => $place_id,
                        'meta_data' => $string,
                        'arr_time' => $arr_time,
                        'dept_time' => $dept_time,
                        'total_time' => $dept_time->diff($start_time)->format('%H:%i:%s'),
                        'delayed_time' => $faker->time()
                    );

                    RouteStops::create($data);
                }
            }
        }
    }
}
