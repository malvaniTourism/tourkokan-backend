<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Console\Command;

class FetchGoogleMapsPlaces extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:places';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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

        // Step 1: Fetch the parent category with code 'destination'
        $destinationCategory = Category::where('code', 'destination')->first();

        // Step 2: Get the IDs of the destination category and its subcategories
        $excludeIds = collect();
        if ($destinationCategory) {
            $excludeIds->push($destinationCategory->id);
            $subCategoryIds = Category::where('parent_id', $destinationCategory->id)->pluck('id');
            $excludeIds = $excludeIds->merge($subCategoryIds);
        }

        $cities = Site::where('category_id', 3)->get();

        foreach ($cities as $key => $value) {
            $categories = Category::select(['name', 'parent_id', 'status'])
            ->whereNull('parent_id')
                ->whereNotIn('id', $excludeIds)
                ->whereStatus(true)
                ->get();

            foreach ($categories as $cateKey => $cateValue) {
                $method = "GET";

                $payload = array(
                    "key" => env('GOOGLE_MAPS_GEOCODING_API_KEY'),
                    "query" => $cateValue['name']. " in " . $value['name'],
                    "type" => "tourist_attraction,lodging"
                );

                $url = "https://maps.googleapis.com/maps/api/place/textsearch/json";

                // logger([$method, $url, $payload, $cateValue->toArray()]);
                // $result = callExternalAPI($method, $url, $payload);

                // logger($result);
                if ($cateKey == 1) {
                    # code...
                    break;
                }
            }
            break;
        }
    }
}
