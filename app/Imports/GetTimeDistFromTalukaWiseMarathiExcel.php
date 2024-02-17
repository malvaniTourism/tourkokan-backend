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
use Illuminate\Support\Facades\Log;

class GetTimeDistFromTalukaWiseMarathiExcel implements ToCollection, WithHeadingRow
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $data)
    {
        foreach ($data as $key => $value) {
            Log::info($value['from_name']);

            $site = Site::where(['id' => 6])->first(); //->update(['logo', $value['from_name']]);

            logger($site->update(['logo' => $value['from_name']]));
            
            if ($key > 2) {
                # code...
                break;

            }
        }

        // logger([$data[288], '']);
    }
}
