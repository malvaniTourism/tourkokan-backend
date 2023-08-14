<?php

namespace App\Imports;

use App\Models\PlaceCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Exception;

class PlaceCategoryImport implements ToCollection, WithHeadingRow
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
                $exist = PlaceCategory::where('name', $value['name'])->first();
                if (!$exist) {
                    $array = array(
                        'name' => $value['name'],
                        'icon' => $faker->imageUrl($width = 400, $height = 400),
                        'meta_data' => $string,
                    );
                    PlaceCategory::create($array);
                }
            }
        } catch (\Throwable $th) {
            throw $th->getMessage();
        }
    }
}
