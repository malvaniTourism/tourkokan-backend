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
        $cities = Site::whereHas('categories', function ($query) {
            $query->where('id', 3);
        })->get();

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
            if (!empty($insertArr)) {
                Gallery::insert($insertArr);
            }
        }
    }
}
