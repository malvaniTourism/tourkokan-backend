<?php

namespace App\Imports;

use App\Models\ProductCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductCategoryImport implements ToCollection, WithHeadingRow
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
                $exist = ProductCategory::where('name', $value['name'])->first();
                if (!$exist) {
                    $array = array(
                        'name' => $value['name'],
                        'icon' => $faker->imageUrl($width = 400, $height = 400),
                        'meta_data' => $string,
                    );
                    ProductCategory::create($array);
                }
            }
        } catch (\Throwable $th) {
            throw $th->getMessage();
        }
    }
}
