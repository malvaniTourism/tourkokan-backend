<?php

namespace App\Jobs;

use App\Models\BusType;
use App\Models\Category;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessRouteImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $faker = \Faker\Factory::create();
            $errors = [];
            foreach ($this->data as $key => $value) {
                $sourceSite = Site::where('name', $value['from_stop_name'])->first();

                if (!$sourceSite) {
                    $errors[] = ['route_no' => $value['route_no'], 'from_stop_name' => $value['from_stop_name']];
                    $this->writeToCsv('error_routes.csv', $errors);
                    $sourceSite = $this->addSite($value['from_stop_name']);
                }

                $destinationSite = Site::where('name', $value['till_stop_name'])->first();

                if (!$destinationSite) {
                    $errors[] = ['route_no' => $value['route_no'], 'till_stop_name' => $value['till_stop_name']];
                    $this->writeToCsv('error_routes.csv', $errors);
                    $destinationSite = $this->addSite($value['till_stop_name']);
                }

                $stopSite = Site::where('name', $value['bstop_name'])->first();

                if (!$stopSite) {
                    $errors[] = ['route_no' => $value['route_no'], 'bstop_name' => $value['bstop_name']];
                    $this->writeToCsv('error_routes.csv', $errors);
                    $stopSite = $this->addSite($value['bstop_name']);
                }

                if (!$sourceSite || !$destinationSite || !$stopSite) {
                    $this->writeToCsv('error_routes.csv', $errors);
                    continue;
                }

                $start_time = new DateTime($faker->dateTimeThisCentury()->format('h:i:s A'));

                $end_time = new DateTime($faker->dateTimeThisCentury($start_time)->format('h:i:s A'));

                $route = Route::where([['name', $value['route_name'], 'route_no' => $value['route_no']]])->first();

                if (!$route) {
                    $route = array(
                        'route_no' => $value['route_no'],
                        'source_place_id' => $sourceSite->id,
                        'destination_place_id' => $destinationSite->id,
                        'bus_type_id' => BusType::where('type', 'Ordinary Express')->first()->id,
                        'name' => $value['route_name'],
                        'description' => null,
                        'meta_data' => null,
                        'start_time' => 0, // $start_time,
                        'end_time' => 0, // $end_time,
                        'total_time' => 0, // $end_time->diff($start_time)->format('%H:%i:%s'),
                        'delayed_time' => 0, // $faker->time(),
                        'distance' => isValidReturn($value, 'dist_km'),
                    );

                    $route = Route::create($route);
                }

                $routStops = RouteStops::where('route_id', $route->id)->get();

                $routeStopExistFilter = array(
                    'route_id' => $route->id,
                    'site_id' => $stopSite->id,
                    'route_no' => $value['route_no']
                );

                $routeStopExists = RouteStops::where($routeStopExistFilter)->first();

                if (!$routeStopExists) {
                    $route_stop = array(
                        'route_no' => $value['route_no'],
                        'serial_no' => count($routStops) + 1,
                        'route_id' => $route->id,
                        'site_id' => $stopSite->id,
                        'meta_data' => null,
                        'arr_time' => 0, //$start_time,
                        'dept_time' => 0, // $end_time,
                        'total_time' => 0, // $end_time->diff($start_time)->format('%H:%i:%s'),
                        'delayed_time' => 0, // $faker->time(),
                        'distance' => $value['dist_km']
                    );

                    RouteStops::create($route_stop);
                }
            }
        } catch (\Throwable $th) {
            logger($th->getMessage());
            throw $th;
        }
    }

    protected function writeToCsv($filename, $data)
    {
        $csvData = '';
        foreach ($data as $error) {
            $csvData .= implode(',', $error) . PHP_EOL;
        }

        Storage::disk('local')->append($filename, $csvData);
    }

    public function addSite($name)
    {
        $site = Site::where('name', $name)->first();
        if ($site) {
            return $site;
        }
        $siteRecord = array();
        $siteRecord['name'] = $name;
        $siteRecord['user_id'] = null;
        $siteRecord['parent_id'] = null;
        $siteRecord['bus_stop_type'] = 'Stop';
        $siteRecord['tag_line'] = '';
        $siteRecord['description'] = '';
        $siteRecord['domain_name'] = null;
        $siteRecord['logo'] = null;
        $siteRecord['icon'] = null;
        $siteRecord['image'] = null;
        $siteRecord['status'] = true;
        $siteRecord['is_hot_place'] = false;
        $siteRecord['latitude'] = null;
        $siteRecord['longitude'] = null;
        $siteRecord['pin_code'] = "" . null;
        $siteRecord['speciality'] = null;
        $siteRecord['rules'] = null;
        $siteRecord['social_media'] = null;
        $siteRecord['meta_data'] = null;

        // Define the category attributes
        $where_category = array(
            'code' => 'other',
            'name' => 'Other',
            'parent_id' => null
        );

        // Attempt to find or create the category
        $category = Category::firstOrCreate($where_category);

        // Check if the category was created or found
        if (!$category) {
            logger("Category could not be created or found");
            return null;
        }
        
        $site = Site::create($siteRecord);

        $site->categories()->attach($category);

        return $site;
    }
}
