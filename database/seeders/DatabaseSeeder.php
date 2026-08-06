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
        $this->call(AppVersionSeeder::class);
        $this->call(RoleSeeder::class);
        $this->call(UserSeeder::class);
        // $this->call(CitySeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(BonusTypeSeeder::class);
        // $this->call(PlaceCategorySeeder::class);
        // $this->call(PlaceSeeder::class);
        $this->call(BannerSeeder::class);
        $this->call(SiteSeeder::class);
        $this->call(BusTypeSeeder::class);

        // ProductCategorySeeder is reinstated in Phase 3 with attribute_schema support.
        // See docs/VENDOR_PRODUCTS_DESIGN.md §6.
        // $this->call(RouteSeeder::class);



       // //   $this->call(GallerySeeder::class);
       // // $this->call(RouteAndRouteStopsSeeder::class);

    }
}
