<?php

namespace Database\Seeders;

use App\Imports\SiteImport;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class SiteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/new_sites.xls';
		Excel::import(new SiteImport, $path);
    }
}
