<?php

namespace App\Console\Commands;

use App\Models\Site;
use GrahamCampbell\ResultType\Success;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadSiteAssets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'upload:siteAssets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is used to upload assets of sites / places.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $sites = Site::all();

        foreach ($sites as $key => $value) {
            $sourceFilePath = public_path('assets/city/' . $value['name'] . '_bg.jpg');
            $destinationFilePath = config('constants.upload_path.site') . '/' . $value['name'] . '_bg.jpg';

            if (File::exists($destinationFilePath)) {
                continue;
            }

            if (!File::exists($sourceFilePath)) {
                // File exists, you can proceed with your logic
                continue;
            }

            // // Copy the file from the public folder to the storage/app folder
            Storage::put($destinationFilePath, file_get_contents($sourceFilePath));

            // // Optionally, you can also delete the original file from the public folder
            // // unlink($sourceFilePath);

            // // Get the downloadable URL for the file

            $updateData = array('image' => Storage::url($destinationFilePath));

            Site::find($value['id'])->update($updateData);
            
            Log::info("FILE STORED" . $value['logo']);
        }
        $this->info('Images uploaded successful!');
    }
}
