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

        $this->call(ProductCategorySeeder::class);
        // Must run after ProductCategorySeeder — it links to categories that seeder creates.
        $this->call(VendorCategorySeeder::class);
        // $this->call(RouteSeeder::class);



       // //   $this->call(GallerySeeder::class);
       // // $this->call(RouteAndRouteStopsSeeder::class);

    }
}
