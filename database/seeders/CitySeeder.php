<?php

namespace Database\Seeders;

use App\Imports\CityImport;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/cities.xls';
		Excel::import(new CityImport, $path);
    }
}
