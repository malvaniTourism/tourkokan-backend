<?php

namespace Database\Seeders;

use App\Models\Roles;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run()
    {
        $superadminRole = Roles::where('code', 'superadmin')->first();

        $users = [
            [
                'project_id' => null,
                'name'       => 'test',
                'email'      => 'test@gmail.com',
                'password'   => bcrypt('Test@123'),
                'uid'        => 'SUPERADMIN1',
                'isVerified' => true,
                'role_code'  => 'superadmin',
            ],
        ];

        foreach ($users as $data) {
            $roleCode = $data['role_code'];
            unset($data['role_code']);

            $existing = User::findByEmail($data['email']);

            if (!$existing) {
                $user = User::create($data);
                $role = Roles::where('code', $roleCode)->first();
                if ($role) {
                    $user->roles()->attach($role->id);
                }
            }
        }
    }
}
