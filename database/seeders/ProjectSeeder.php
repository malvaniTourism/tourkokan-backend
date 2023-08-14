<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\City;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = \Faker\Factory::create();

        $string = '[{"Format":"I25","Content":"172284201241"}]';
        
        for($i = 0; $i < 5; $i++){
            DB::table("projects")->insert([
                'category_id' => User::all()->random()->id,
                'city_id' => City::all()->random()->id,
                'user_id' =>  $i/3==0 ? null :  User::all()->random()->id,
                'name' => $faker->word,
                'domain_name' => $faker->randomElement(['www.google.com', 'www.pranavkamble.in', 'www.youtube.com', 'www.laravel.com', 'wwwvasantvijay.com']),
                'logo' => $faker->randomElement(['storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg']),
                'fevicon' => $faker->randomElement(['storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg']),
                'description' => $faker->realText(),
                'ratings' => $faker->numberBetween(1, 5),
                'picture' => $faker->word,
                'start_price' => $faker->numberBetween(100, 50000),
                'speciality' => $faker->word,
                'link_status' => $faker->boolean(),
                'project_meta' => $string,
            ]);
        }
    }
}
