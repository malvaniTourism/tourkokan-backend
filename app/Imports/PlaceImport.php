<?php

namespace App\Imports;

use App\Models\City;
use App\Models\Place;
use App\Models\PlaceCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PlaceImport implements ToCollection, WithHeadingRow
{
    /**
     * @param Collection $collection
     */
    public function collection(Collection $data)
    {
        try {
            $faker = \Faker\Factory::create();

            $string = '[{"Format":"I25","Content":"172284201241"}]';


            foreach ($data as $key => $value) {
                $place_record = array();
                $place_record['name'] = $value['name'];
                $place_record['latitude'] = $value['latitude'];
                $place_record['longitude'] = $value['longitude'];

                $where_city = array(
                    'name' => $value['city_name']
                );

                $city = City::where($where_city)->first();

                if (!$city) {
                    echo "Invalid City ". $value['city_name'];
                    continue;
                }

                $place_record['city_id'] = $city->id;


                $where_place_category = array(
                    'name' => $value['place_category_name']
                );

                $place_category = PlaceCategory::where($where_place_category)->first();

                if ($place_category) {
                    $place_record['place_category_id'] = $place_category->id;
                }

                $where_place = array(
                    'name' => $value['name'],
                    'city_id' => $city->id
                );

                $exist = Place::where($where_place)->first();

                if (!$exist) {
                    if (
                        ($value['name'] == $value['city_name']) &&
                        ($value['city_name'] == $city->name) &&
                        ($value['name'] == $city->name) &&
                        $value['parent_place_name'] === null
                    ) {
                        $place_record['parent_id'] = null;
                        $place_record['latitude'] = $city->latitude;
                        $place_record['longitude'] = $city->longitude;
                    } else {
                        if ($value['parent_place_name'] != null) {
                            $where_parent_place = array(
                                'name' => $value['parent_place_name'],
                                'parent_id' => null
                            );

                            $parent_place = Place::where($where_parent_place)->first();

                            if ($parent_place) {
                                $place_record['parent_id'] = $parent_place->id;
                            }
                        }
                    }

                    Place::create($place_record);
                }
            }
        } catch (\Throwable $th) {
            throw $th->getMessage();
        }
    }
}
