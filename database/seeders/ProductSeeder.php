<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Projects;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
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
        
        for($i = 0; $i < 1000; $i++){
            DB::table("products")->insert([
                'name' => $faker->word,
                'project_id' =>  Projects::all()->random()->id,//$faker->numberBetween(1, Projects::count()),
                'price' => $faker->numberBetween(100, 50000),
                'description' => $faker->realText(),
                'ratings' => $faker->numberBetween(1, 5),
                'picture' => $faker->randomElement(['storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg']),
            ]);
        }
    }
}
