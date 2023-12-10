<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
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
        
        for($i = 0; $i < 10; $i++){
            DB::table("categories")->insert([
                'name' => $faker->randomElement(['Adventure tourism', 'Beach Tourism', 'Cultural tourism', 'Eco tourism', 'Medical tourism', 'Wildlife tourism', 'Agro Tourism', 'Hotels', 'Restaurants', 'Raw Houses']),
                'image_url' => $faker->randomElement(['storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg']),
                'description' => $faker->realText(),
                'meta_data' => $string
            ]);
        }
    }
}
