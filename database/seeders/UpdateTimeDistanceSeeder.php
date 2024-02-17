<?php

namespace Database\Seeders;

use App\Imports\GetTimeDistFromTalukaWiseMarathiExcel;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class UpdateTimeDistanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/Deogad.xls';
		Excel::import(new GetTimeDistFromTalukaWiseMarathiExcel, $path);
    }
}
