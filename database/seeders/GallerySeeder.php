<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Gallery;
use App\Models\Site;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GallerySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $cities = Site::where('category_id', 3)
            ->get();

        foreach ($cities as $key => $value) {

            $data = getData($value->id, 'Site');

            if (!$data) {
                Log::debug("invalid model");
                continue;
            }

            $commentableType = "App\\Models\\Site";

            // Define the directory path
            $directoryPath = public_path('assets/city/' . $value->name);

            // Retrieve all files from the directory
            $files = File::allFiles($directoryPath);

            $insertArr = [];

            // Iterate through the files and print their paths
            foreach ($files as $file) {
                // logger($file->getFilename());

                $exist = Gallery::where('title',  pathinfo($file->getFilename(), PATHINFO_FILENAME))->first();

                if (!$exist) {
                    $sourceFilePath = public_path('assets/city/' . $value->name . '/' . $file->getFilename());
                    $destinationFilePath = config('constants.upload_path.site') . '/' . $value->name . '/' . $file->getFilename();

                    // Copy the file from the public folder to the storage/app folder
                    Storage::put($destinationFilePath, file_get_contents($sourceFilePath));

                    $path = Storage::url($destinationFilePath);

                    $insertArr[] = array(
                        'title' => $file->getFilename(),
                        'description' => Str::random(16),
                        'path' => $path,
                        'galleryable_type' => $commentableType,
                        'galleryable_id' => $value->id,
                    );
                }
            }
            logger($insertArr);
            if (!empty($insertArr)) {
                Gallery::insert($insertArr);
                logger("insert success");
            }
            logger("=======================");
        }



        // $array = array(
        //     [
        //         'name' => 'Joining Bonus coins',
        //         'code' => 'joining_bonus_coins',
        //         'description' =>  'User will get this bonus on first time of registartion in tourkokan.',
        //         'amount' => '1000'
        //     ],
        //     [
        //         'name' => 'Referral Bonus coins',
        //         'code' => 'referral_bonus_coins',
        //         'description' =>  'User will get this bonus on refer tourkokan to any of his friend or relative and of successfull registartion of user in tourkokan.',
        //         'amount' => '500'
        //     ]
        // );

        // $newRecords = [];

        // foreach ($array as $value) {
        //     $exist = Gallery::where('name', $value['name'])->where('code', $value['code'])->first();

        //     if (!$exist) {
        //         if (isValidReturn($value, 'path')) {
        //             $sourceFilePath = public_path('assets/bustypelogo' . $value['path']);
        //             $destinationFilePath = config('constants.upload_path.busType') . '/' . $value['type'] . $value['path'];

        //             // Copy the file from the public folder to the storage/app folder
        //             Storage::put($destinationFilePath, file_get_contents($sourceFilePath));

        //             // Optionally, you can also delete the original file from the public folder
        //             // unlink($sourceFilePath);

        //             // Get the downloadable URL for the file

        //             $value['logo'] = Storage::url($destinationFilePath);

        //             Log::info("FILE STORED" . $value['logo']);
        //         }

        //         unset($value['path']);

        //         $newRecords[] = $value;
        //     }
        // }

        // if (!empty($newRecords)) {
        //     Gallery::insert($newRecords);
        // }
    }
}
