<?php

namespace App\Imports;

use App\Jobs\ProcessRouteImport;
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
        return 100; // Adjust the chunk size as needed
    }

    /**
     * @param Collection $collection
     */
    public function collection(Collection $data)
    {
        $faker = \Faker\Factory::create();

        $dataChunks = $data->chunk(50); // Adjust the chunk size as needed

        foreach ($dataChunks as $chunk) {
            ProcessRouteImport::dispatch($chunk);
        }
    }
}
