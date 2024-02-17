<?php

namespace Database\Seeders;

use App\Models\Projects;
use App\Models\Roles;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
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
                'role_id' => Roles::where("code", "SuperAdmin")->first()->id,
                'project_id' => null, //Projects::all()->random()->id,
                'name' => 'test',
                'email' => 'test@gmail.com',
                'password' => bcrypt("Test@123")
            ]
        );

        foreach ($array as $key => $value) {
            $exist = User::where('email', $value['email'])->first();

            if (!$exist)
                User::create($value);
        }
    }
}
