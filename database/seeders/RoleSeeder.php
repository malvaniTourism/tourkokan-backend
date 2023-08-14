<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\City;
use App\Models\Category;
use App\Models\Roles;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $array = array(
            [
                'name' => 'superadmin',
                'display_name' => 'Super Admin'
            ],
            [
                'name' => 'admin',
                'display_name' => 'Admin'
            ],
            [
                'name' => 'user',
                'display_name' => 'User'
            ],
            [
                'name' => 'tourist',
                'display_name' => 'Tourist'
            ],
            [
                'name' => 'tour_guide',
                'display_name' => 'Tour Guide'
            ],
            [
                'name' => 'blogger',
                'display_name' => 'Blogger'
            ],
            [
                'name' => 'vlogger',
                'display_name' => 'Vlogger'
            ]
        );

        foreach ($array as $key => $value) {
            $exist = Roles::where([['name', $value['name']], ['display_name', $value['display_name']]])->first();

            if (!$exist)
                Roles::create($value);
        }
    }
}
