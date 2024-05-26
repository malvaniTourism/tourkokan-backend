<?php

namespace Database\Seeders;

use App\Imports\EventTypeImport;
use Illuminate\Database\Seeder;
use Maatwebsite\Excel\Facades\Excel;

class EventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $path = 'excels/event_types.xls';
		Excel::import(new EventTypeImport, $path);
    }
}
