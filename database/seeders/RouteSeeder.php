<?php

namespace Database\Seeders;

use App\Models\BusType;
use App\Models\Place;
use App\Models\Route;
use DateTime;
use Illuminate\Database\Seeder;

class RouteSeeder extends Seeder
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

        for ($i = 0; $i < 10; $i++) {
            $start_time = new DateTime($faker->dateTimeThisCentury()->format('h:i:s A'));
            $end_time = new DateTime($faker->dateTimeThisCentury($start_time)->format('h:i:s A'));

            $source_place =  Place::all()->random();
            $destination_place = Place::all()->except($source_place->id)->random();

            if ($source_place->id == null || $destination_place->id == null)
                continue;

            $exist = Route::where("source_place_id", $source_place->id)
                ->where("destination_place_id", $destination_place->id)
                ->first();

            if (!$exist) {
                $data = array(
                    'source_place_id' => $source_place->id,
                    'destination_place_id' => $destination_place->id,
                    'bus_type_id' => BusType::all()->random()->id,
                    'name' => $source_place->name . "-> To ->" . $destination_place->name,
                    'description' => $faker->text(),
                    'meta_data' => $string,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'total_time' => $end_time->diff($start_time),
                    'delayed_time' => $faker->time()
                );

                Route::create($data);
            }
        }
    }
}
