<?php

namespace App\Imports;

use App\Models\City;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CityImport implements ToCollection, WithHeadingRow
{
    /**
     * @param Collection $collection
     */
    public function collection(Collection $data)
    {
        try {
            foreach ($data as $key => $value) {
                $exist = City::where('name', $value['name'])
                    ->first();

                if (isValidReturn($value, 'image_url')) {

                    $sourceFilePath = public_path('assets/city/' . $value['image_url']);

                    $destinationFilePath = config('constants.upload_path.city') . $value['name'] . '/' .  $value['image_url'];

                    // Copy the file from the public folder to the storage/app folder
                    Storage::put($destinationFilePath, file_get_contents($sourceFilePath));

                    // Optionally, you can also delete the original file from the public folder
                    // unlink($sourceFilePath);

                    // Get the downloadable URL for the file

                    $value['image_url'] = Storage::url($destinationFilePath);

                    Log::info("FILE STORED" . $value['image_url']);
                }



                if (isValidReturn($value, 'bg_image_url')) {

                    $sourceFilePath = public_path('assets/city/' . $value['bg_image_url']);

                    $destinationFilePath = config('constants.upload_path.city') . $value['name'] . '/' .  $value['bg_image_url'];

                    // Copy the file from the public folder to the storage/app folder
                    Storage::put($destinationFilePath, file_get_contents($sourceFilePath));

                    // Optionally, you can also delete the original file from the public folder
                    // unlink($sourceFilePath);

                    // Get the downloadable URL for the file

                    $value['bg_image_url'] = Storage::url($destinationFilePath);

                    Log::info("FILE STORED" . $value['bg_image_url']);
                }

                $array = array(
                    'name' => $value['name'],
                    'description' => $value['description'],
                    'tag_line' => $value['tag_line'],
                    'image_url' => $value['image_url'],
                    'bg_image_url' => $value['bg_image_url'],
                    'latitude' => $value['latitude'],
                    'longitude' => $value['longitude']
                );

                if (!$exist) {
                    City::create($array);
                    continue;
                }

                City::find($exist->id)->update($array);
            }
        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage());
        }
    }
}
