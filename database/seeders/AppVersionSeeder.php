<?php

namespace Database\Seeders;

use App\Models\AppVersion;
use Illuminate\Database\Seeder;

class AppVersionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $AppVersion = AppVersion::latest()->first();

        if (!$AppVersion) {
            AppVersion::create([
                'platform' => 'android',
                'version_number' => '1.0.0',
                'release_date' => time(),
                'release_notes' => 'Initial Testing',
                'update_url' => 'na'
            ]);
            AppVersion::create([
                'platform' => 'ios',
                'version_number' => '1.0.0',
                'release_date' => time(),
                'release_notes' => 'Initial Testing',
                'update_url' => 'na'
            ]);
        }
    }
}
