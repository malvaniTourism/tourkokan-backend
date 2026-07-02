<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Gallery;
use App\Models\Site;
use Exception;
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
            $query->where('name', 'city');
        })->get();

        $parent_site = Site::where('name', 'Sindhudurg')->first();

        if ($parent_site) {
            $parent_id = $parent_site->id;
            // Proceed with your logic using $parent_id
        } else {
            // Log an error message
            Log::error('Site with name "Sindhudurg" not found. Seeder terminated.');

            // Terminate the seeder by throwing an exception
            throw new Exception('Site with name "Sindhudurg" not found. Seeder terminated.');
        }

        foreach ($cities as $value) {
            $data = getData($value->id, 'Site');

            if (!$data) {
                Log::debug("Invalid model for city: {$value->name}");
                continue;
            }

            $commentableType = "App\\Models\\Site";

            // Define the directory path
            $directoryPath = public_path('assets' . DIRECTORY_SEPARATOR . 'city' . DIRECTORY_SEPARATOR . $value->name);

            if (!is_dir($directoryPath)) {
                Log::debug("Directory does not exist: {$directoryPath}");
                continue;
            }

            // Retrieve all files from the directory
            $files = File::allFiles($directoryPath);

            foreach ($files as $file) {
                $subSite = $file->getRelativePathname();

                // Use DIRECTORY_SEPARATOR to split paths
                $parts = explode(DIRECTORY_SEPARATOR, $subSite);
                $subSiteName = $parts[0];

                logger([$subSiteName, $subSite]);
                $site = Site::where("name", $subSiteName)
                    ->where("parent_id", $value->id)
                    ->orWhere(function ($query) use ($subSiteName, $parent_id) {
                        $query->where("parent_id", $parent_id)
                            ->where("name", $subSiteName);
                    })
                    ->first();

                // Check if a Site record exists with the specified name
                if (!$site) {
                    logger("Site not found for: $subSiteName in city: {$value->name}");
                    continue;
                }

                $title = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                $exist = Gallery::where('title', $title)->exists();

                if ($exist) {
                    logger("Gallery item already exists for title: $title");
                    continue;
                }

                $sourceFilePath = $directoryPath . DIRECTORY_SEPARATOR . $subSite;
                $destinationFilePath = config('constants.upload_path.site') . DIRECTORY_SEPARATOR . $value->name . DIRECTORY_SEPARATOR . $subSiteName . DIRECTORY_SEPARATOR . $file->getFilename();

                // Ensure the destination path is valid and not a directory
                if (is_dir($destinationFilePath)) {
                    logger("Error: Destination path is a directory: {$destinationFilePath}");
                    continue; // Skip this iteration if it's a directory
                }

                // Proceed with the upload
                $success = Storage::put($destinationFilePath, file_get_contents($sourceFilePath));
                if (!$success) {
                    logger("Failed to upload file to: {$destinationFilePath}");
                }

                $path = Storage::url($destinationFilePath);

                Gallery::create([
                    'title' => $title,
                    'description' => Str::random(16),
                    'path' => $path,
                    'galleryable_type' => $commentableType,
                    'galleryable_id' => isValidReturn($site, 'id', $value->id),
                ]);
            }
        }
    }
}
