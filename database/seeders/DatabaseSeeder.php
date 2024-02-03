<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // \App\Models\User::factory(10)->create();

        $this->call(RoleSeeder::class);
        $this->call(UserSeeder::class);
        // $this->call(CitySeeder::class);
        $this->call(CategorySeeder::class);
        // $this->call(PlaceCategorySeeder::class);
        // $this->call(PlaceSeeder::class);
        // $this->call(ProjectSeeder::class);
        $this->call(SiteSeeder::class);
        $this->call(BusTypeSeeder::class);
        // $this->call(RouteSeeder::class);
        $this->call(RouteAndRouteStopsSeeder::class);
        $this->call(ProductCategorySeeder::class);

    }
}
