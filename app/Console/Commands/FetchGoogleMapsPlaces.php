<?php

namespace App\Console\Commands;

use App\Exports\CrawlGoogleMapDownloadPlace;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Command is used for fetching all places by categories in each city';

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
        $cities = Site::where('category_id', 3)->get();

        foreach ($cities as $key => $value) {
            Log::info("city " . $value['name']);
            if ($value['name'] != "Devgad") {
               continue;
            }
            $categories = Category::select(['name', 'parent_id', 'status'])
                ->whereNotNull('parent_id')
                ->whereStatus(true)
                ->get();

            foreach ($categories as $cateKey => $cateValue) {
                Log::info("category " . $cateValue['name']);

                $method = "GET";

                $payload = array(
                    "key" => env('GOOGLE_MAPS_GEOCODING_API_KEY'),
                    "query" => $cateValue['name'] . " in " . $value['name'],
                    'radius' => 5000,
                    // "type" => "tourist_attraction"
                );

                $url = "https://maps.googleapis.com/maps/api/place/textsearch/json";

                $newData = $this->fetchAndStoreData($method, $url, $payload);

                // Store any remaining data
                if (!empty($newData)) {
                    $this->storeDataInExcel($newData);
                }
            }
        }
    }

    function storeDataInExcel($data)
    {
        $fileName = time() . '_placesfromgoogle.xlsx';
        Excel::store(new CrawlGoogleMapDownloadPlace($data), $fileName, 'local');
    }

    public function fetchAndStoreData($method, $url, $payload, $data = [], $pageToken = null)
    {
        Log::info("inside fetch and store method");

        if ($pageToken) {
            Log::info("pagination present");
            $payload['pagetoken'] = $pageToken;
            sleep(2); // Pause to comply with the API requirement for subsequent requests
        }

        Log::info(["payload" => $payload, "url" => $url]);

        $response = callExternalAPI($method, $url, $payload);

        if ($response && $response['status'] === 'OK') {
            foreach ($response['results'] as $result) {
                $location = isValidReturn($result['geometry'], 'location');
                $data[] = [
                    'business_status' => isValidReturn($result, 'business_status'),
                    'formatted_address' => isValidReturn($result, 'formatted_address'),
                    'latitude' => isValidReturn($location, 'lat'),
                    'longitude' => isValidReturn($location, 'lng'),
                    'name' => isValidReturn($result, 'name'),
                    'place_id' => isValidReturn($result, 'place_id'),
                    'rating' => isValidReturn($result, 'rating'),
                    'reference' => isValidReturn($result, 'reference'),
                    'types' => json_encode(isValidReturn($result, 'types')),
                    'user_ratings_total' => isValidReturn($result, 'user_ratings_total'),
                    'viewport' => json_encode(isValidReturn($result, 'geometry')),
                    'photos' => json_encode(isValidReturn($result, 'photos')),
                    'payload' => $payload
                ];

                // Store the data in chunks to prevent memory overflow
                if (count($data) >= 1000) {
                    $this->storeDataInExcel($data);
                    $data = []; // Reset data array after storing
                }
            }

            // Check if there is a next_page_token and call the function recursively
            if (isset($response['next_page_token'])) {
                Log::info("Next page recursive call done.");
                Log::info(["Next page token " => $response['next_page_token']]);

                $data = $this->fetchAndStoreData($method, $url, $payload, $data, $response['next_page_token']);
            }
        }

        return $data;
    }
}
