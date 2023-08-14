<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\City;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class BlogSeeder extends Seeder
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
        
        for($i = 0; $i < 15; $i++){
            DB::table("blogs")->insert([
                'category_id' => Category::all()->random()->id,
                'name' => $faker->word,
                'title' => $faker->sentence,
                'description' => $faker->realText(),
                'image' => $faker->randomElement(['/storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', 'storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg', '/storage/assets/places/4/Devgad Beach/nEZKix4gsHXTOQ9as6NcSLzllpVZsaJXWOyOWJLg.jpg']),
                'ratings' => $faker->numberBetween(1, 5),
                'count' => $faker->numberBetween(1, 5),
            ]);
        }
    }
}
