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
                'code' => 'superadmin',
                'name' => 'Super Admin'
            ],
            [
                'code' => 'admin',
                'name' => 'Admin'
            ],
            [
                'code' => 'user',
                'name' => 'User'
            ],
            [
                'code' => 'tourist',
                'name' => 'Tourist'
            ],
            [
                'code' => 'tour_guide',
                'name' => 'Tour Guide'
            ],
            [
                'code' => 'blogger',
                'name' => 'Blogger'
            ],
            [
                'code' => 'vlogger',
                'name' => 'Vlogger'
            ]
        );

        foreach ($array as $key => $value) {
            $exist = Roles::where([
                ['code', $value['code']], ['name', $value['name']]
            ])->first();

            if (!$exist)
                Roles::create($value);
        }
    }
}
