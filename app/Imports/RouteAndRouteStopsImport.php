<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

use App\Models\Address;
use App\Models\BusType;
use App\Models\Category;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use DateTime;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class RouteAndRouteStopsImport implements ToCollection, WithHeadingRow, WithChunkReading
{

    public function chunkSize(): int
    {
        return 50; // Adjust the chunk size as needed
    }

    /**
     * @param Collection $collection
     */
    public function collection(Collection $data)
    {
        $faker = \Faker\Factory::create();

        try {
            foreach ($data as $key => $value) {

                #add routes in route table
                $sourceSite = Site::where('name', $value['from_stop_name'])->first();

                if (!$sourceSite) {
                    logger(1);
                    $sourceSite = $this->addSite($value['from_stop_name']);
                }

                $destinationSite = Site::where('name', $value['till_stop_name'])->first();

                if (!$destinationSite) {
                    logger(2);

                    $destinationSite = $this->addSite($value['till_stop_name']);
                }

                $stopSite = Site::where('name', $value['bstop_name'])->first();

                if (!$stopSite) {
                    $stopSite = $this->addSite($value['bstop_name']);
                }

                if (!$sourceSite || !$destinationSite || !$stopSite) {
                    continue;
                }

                $start_time = new DateTime($faker->dateTimeThisCentury()->format('h:i:s A'));
                $end_time = new DateTime($faker->dateTimeThisCentury($start_time)->format('h:i:s A'));

                $route = Route::where('name', $value['route_name'])->first();
                // logger($key);
                if (!$route) {
                    $route = array(
                        'source_place_id' => $sourceSite->id,
                        'destination_place_id' => $destinationSite->id,
                        'bus_type_id' => BusType::where('type', 'Ordinary Express')->first()->id,
                        'name' => $value['route_name'],
                        'description' => null,
                        'meta_data' => null,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'total_time' => $end_time->diff($start_time),
                        'delayed_time' => $faker->time()
                    );

                    $route = Route::create($route);
                }

                $routStops = RouteStops::where('route_id', $route->id)->get();

                $routeStopExistFilter = array(
                    'route_id' => $route->id,
                    'site_id' => $stopSite->id
                );

                $routeStopExists = RouteStops::where($routeStopExistFilter)->first();

                if (!$routeStopExists) {
                    $route_stop = array(
                        'serial_no' => count($routStops) + 1,
                        'route_id' => $route->id,
                        'site_id' => $stopSite->id,
                        'meta_data' => null,
                        'arr_time' => $start_time,
                        'dept_time' => $end_time,
                        'total_time' => $end_time->diff($start_time)->format('%H:%i:%s'),
                        'delayed_time' => $faker->time()
                        // 'km' => $value['dist_km'] add in migartion
                    );

                    RouteStops::create($route_stop);
                }
            }
        } catch (\Throwable $th) {
            logger($th->getMessage());
            throw $th;
        }
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

        $where_category = array(
            'code' => 'Other'
        );

        $category = Category::where($where_category)->first();
        if (!$category) {
            logger("invalid category");
            return null;
        }

        $siteRecord['category_id'] = isValidReturn($category, 'id');
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
        $site = Site::create($siteRecord);

        return $site;
    }
}
