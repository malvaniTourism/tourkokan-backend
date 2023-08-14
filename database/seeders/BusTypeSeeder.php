<?php

namespace Database\Seeders;

use App\Models\BusType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BusTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = \Faker\Factory::create();

        $string = '[{"color_code":"I25","Content":"172284201241"}]';

        $array = array(
            [
                'type' => 'AC-Shivnery',
                'path' => '/AC-Shivnery.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#87b4d5","Content":"172284201241"}]'
            ],
            [
                'type' => 'Hirkani Semi Luxury',
                'path' => '/Hirkani Semi Luxury.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#7d377f","Content":"172284201241"}]'
            ],
            [
                'type' => 'Night Express',
                'path' => null,
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#7d377f","Content":"172284201241"}]'
            ],
            [
                'type' => 'Ordinary Express',
                'path' => '/Day Ordinary.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#c42a2e","Content":"172284201241"}]'
            ],
            [
                'type' => 'Day Ordinary',
                'path' => '/Day Ordinary.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#c42a2e","Content":"172284201241"}]'
            ],
            [
                'type' => 'AC-Sheetal',
                'path' => null,
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#e7e8e7","Content":"172284201241"}]'
            ],
            [
                'type' => 'AC-Ashwamedh',
                'path' => '/AC-Ashwamedh.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#eb552c","Content":"172284201241"}]'
            ],
            [
                'type' => 'Volvo Ac',
                'path' => null,
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#e7e8e7","Content":"172284201241"}]'
            ],
            [
                'type' => 'Hirkani',
                'path' => '/Hirkani.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#3cac6c","Content":"172284201241"}]'
            ],
            [
                'type' => 'Shivshahi',
                'path' => '/Shivshahi.svg',
                'logo' =>  $faker->imageUrl($width = 400, $height = 400),
                'meta_data' => '[{"color_code":"#e7e8e7","Content":"172284201241"}]'
            ]
        );

        foreach ($array as $key => $value) {
            $exist = BusType::where('type', $value['type'])->first();

            if (!$exist) {
                if (isValidReturn($value, 'path')) {
                    $sourceFilePath = public_path('assets/bustypelogo' . $value['path']);
                    $destinationFilePath = config('constants.upload_path.busType') . '/' . $value['type'] . $value['path'];

                    // Copy the file from the public folder to the storage/app folder
                    Storage::put($destinationFilePath, file_get_contents($sourceFilePath));

                    // Optionally, you can also delete the original file from the public folder
                    // unlink($sourceFilePath);

                    // Get the downloadable URL for the file

                    $value['logo'] = Storage::url($destinationFilePath);

                    Log::info("FILE STORED" . $value['logo']);
                }

                unset($value['path']);

                BusType::create($value);
            }
        }
    }
}
