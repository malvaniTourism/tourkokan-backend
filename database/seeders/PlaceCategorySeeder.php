<?php

namespace Database\Seeders;

use App\Imports\PlaceCategoryImport;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class PlaceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/place_categories.xls';
		Excel::import(new PlaceCategoryImport, $path);
    }
}
