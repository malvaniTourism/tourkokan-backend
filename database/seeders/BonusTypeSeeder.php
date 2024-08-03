<?php

namespace Database\Seeders;

use App\Models\BonusTypes;
use Illuminate\Database\Seeder;

class BonusTypeSeeder extends Seeder
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
                'name' => 'Joining Bonus coins',
                'code' => 'joining_bonus_coins',
                'description' =>  'User will get this bonus on first time of registartion in tourkokan.',
                'amount' => '1000'
            ],
            [
                'name' => 'Referral Bonus coins',
                'code' => 'referral_bonus_coins',
                'description' =>  'User will get this bonus on refer tourkokan to any of his friend or relative and of successfull registartion of user in tourkokan.',
                'amount' => '500'
            ]
        );

        $newRecords = [];

        foreach ($array as $value) {
            $exist = BonusTypes::where('name', $value['name'])->where('code', $value['code'])->first();

            if (!$exist) {
                $newRecords[] = $value;
            }
        }

        if (!empty($newRecords)) {
            BonusTypes::insert($newRecords);
        }
    }
}
