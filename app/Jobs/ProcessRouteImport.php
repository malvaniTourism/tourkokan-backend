<?php

namespace App\Jobs;

use App\Models\BusType;
use App\Models\Category;
use App\Models\Route;
use App\Models\RouteStops;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use DateTime;
use Illuminate\Support\Facades\Storage;

class ProcessRouteImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    /** Per-file error log so each taluka's failures stay separate. */
    protected $errorFile;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data, $errorFile = 'error_routes.csv')
    {
        $this->data      = $data;
        $this->errorFile = $errorFile;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $errors = [];
            foreach ($this->data as $key => $value) {
                $sourceSite = Site::where('name', $value['from_stop_name'])->first();

                if (!$sourceSite) {
                    $errors[] = ['route_no' => $value['route_no'], 'from_stop_name' => $value['from_stop_name']];
                    $this->writeToCsv($this->errorFile, $errors);
                    $sourceSite = $this->addSite($value['from_stop_name']);
                }

                $destinationSite = Site::where('name', $value['till_stop_name'])->first();

                if (!$destinationSite) {
                    $errors[] = ['route_no' => $value['route_no'], 'till_stop_name' => $value['till_stop_name']];
                    $this->writeToCsv($this->errorFile, $errors);
                    $destinationSite = $this->addSite($value['till_stop_name']);
                }

                $stopSite = Site::where('name', $value['bstop_name'])->first();

                if (!$stopSite) {
                    $errors[] = ['route_no' => $value['route_no'], 'bstop_name' => $value['bstop_name']];
                    $this->writeToCsv($this->errorFile, $errors);
                    $stopSite = $this->addSite($value['bstop_name']);
                }

                if (!$sourceSite || !$destinationSite || !$stopSite) {
                    $this->writeToCsv($this->errorFile, $errors);
                    continue;
                }

                $route = Route::where('route_no', $value['route_no'])->first();

                if (!$route) {
                    // Trip columns only exist on the taluka CSVs; the older
                    // AllRoutesWithStops export has none, so every one is optional.
                    $startTime = $this->toTime(isValidReturn($value, 'departure_time'));
                    $endTime   = $this->toTime(isValidReturn($value, 'arrival_time'));

                    $route = array(
                        'route_no' => $value['route_no'],
                        'source_place_id' => $sourceSite->id,
                        'destination_place_id' => $destinationSite->id,
                        'bus_type_id' => BusType::where('type', 'Ordinary Express')->first()->id,
                        'name' => $value['route_name'],
                        'description' => null,
                        'meta_data' => $this->tripMeta($value),
                        'start_time' => $startTime ?? '00:00:00',
                        'end_time' => $endTime ?? '00:00:00',
                        'total_time' => $this->duration($startTime, $endTime),
                        'delayed_time' => '00:00:00',
                        'working_days' => isValidReturn($value, 'frequency'),
                        // Trip distance (whole route) beats dist_km, which is the
                        // running total at whichever stop happened to come first.
                        'distance' => isValidReturn($value, 'distance_km') ?? isValidReturn($value, 'dist_km'),
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

    /**
     * "09:30" / "9:30" -> "09:30:00"; null when absent or unparseable.
     *
     * Hand-typed duty sheets use whatever separator the typist hit — "9..05",
     * "9.05", "9,05" all appear. Treat any run of . , ; : as one separator
     * rather than discarding the row, which would silently zero start_time.
     */
    protected function toTime($raw)
    {
        if (!$raw) {
            return null;
        }

        $normalised = preg_replace('/[.,;:]+/', ':', trim($raw));

        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $normalised, $m)) {
            return null;
        }

        if ($m[1] > 23 || $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }

    /** Journey length; rolls past midnight when arrival precedes departure. */
    protected function duration($start, $end)
    {
        if (!$start || !$end) {
            return '00:00:00';
        }

        $from = new DateTime($start);
        $to   = new DateTime($end);

        if ($to < $from) {
            $to->modify('+1 day');
        }

        return $from->diff($to)->format('%H:%I:%S');
    }

    /** Trip attributes the routes table has no column for. */
    protected function tripMeta($value)
    {
        $meta = array_filter([
            'trip_type'   => isValidReturn($value, 'trip_type'),
            'school_trip' => isValidReturn($value, 'school_trip'),
            'remark'      => isValidReturn($value, 'remark'),
            'rest_remark' => isValidReturn($value, 'rest_remark'),
            'source_mr'   => isValidReturn($value, 'source_marathi'),
            'dest_mr'     => isValidReturn($value, 'destination_marathi'),
        ], fn($v) => $v !== null && $v !== '');

        return $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
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
