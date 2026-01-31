<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Banner;
use App\Models\BannerPackage;
use App\Models\BannerPlacement;
use App\Models\User;
use Carbon\Carbon;

class BannerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (env('APP_ENV')  == 'prod') {
            return;
        }
        // 1. Seed Banner Placements
        $placements = [
            // --- Landing Page ---
            [
                'code' => 'HOME_HERO',
                'description' => 'Main hero banner on homepage (Top)',
                'screen' => 'Home',
                'width' => 1200,
                'height' => 600,
                'is_active' => true,
            ],
            [
                'code' => 'HOME_MIDDLE',
                'description' => 'Middle section banner on homepage',
                'screen' => 'Home',
                'width' => 1200,
                'height' => 400,
                'is_active' => true,
            ],
            [
                'code' => 'HOME_FOOTER',
                'description' => 'Footer banner on homepage',
                'screen' => 'Home',
                'width' => 1200,
                'height' => 200,
                'is_active' => true,
            ],
            [
                'code' => 'APP_SPLASH',
                'description' => 'Full screen splash banner on app load',
                'screen' => 'App',
                'width' => 1080,
                'height' => 1920,
                'is_active' => true,
            ],
            
            // --- City Details Page ---
            [
                'code' => 'CITY_MIDDLE',
                'description' => 'Middle banner on city details page',
                'screen' => 'CityDetail',
                'width' => 1200,
                'height' => 400,
                'is_active' => true,
            ],
            [
                'code' => 'CITY_FOOTER',
                'description' => 'Footer banner on city details page',
                'screen' => 'CityDetail',
                'width' => 1200,
                'height' => 200,
                'is_active' => true,
            ],

            // --- Route Details Page ---
            [
                'code' => 'ROUTE_DETAIL_MIDDLE',
                'description' => 'Middle banner on route details page',
                'screen' => 'RouteDetail',
                'width' => 1200,
                'height' => 400,
                'is_active' => true,
            ],
            [
                'code' => 'ROUTE_DETAIL_FOOTER',
                'description' => 'Footer banner on route details page',
                'screen' => 'RouteDetail',
                'width' => 1200,
                'height' => 200,
                'is_active' => true,
            ],

            // --- Route List Page ---
            [
                'code' => 'ROUTE_LIST_MIDDLE',
                'description' => 'Middle banner on route list page',
                'screen' => 'RouteList',
                'width' => 1200,
                'height' => 400,
                'is_active' => true,
            ],
            [
                'code' => 'ROUTE_LIST_FOOTER',
                'description' => 'Footer banner on route list page',
                'screen' => 'RouteList',
                'width' => 1200,
                'height' => 200,
                'is_active' => true,
            ],
        ];

        foreach ($placements as $data) {
            BannerPlacement::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }

        // 2. Seed Banner Packages
        // Collect all placement codes
        $allPlacementCodes = array_column($placements, 'code');

        $packages = [
            [
                'name' => 'Basic Starter',
                'duration_days' => 7,
                'price' => 49.99,
                'allowed_placements' => ['HOME_MIDDLE', 'CITY_MIDDLE', 'ROUTE_LIST_MIDDLE', 'ROUTE_DETAIL_MIDDLE'],
                'is_active' => true,
            ],
            [
                'name' => 'Standard Growth',
                'duration_days' => 30,
                'price' => 199.99,
                'allowed_placements' => ['HOME_HERO', 'HOME_FOOTER', 'CITY_FOOTER', 'ROUTE_LIST_FOOTER', 'ROUTE_DETAIL_FOOTER'],
                'is_active' => true,
            ],
            [
                'name' => 'Premium Dominance',
                'duration_days' => 90,
                'price' => 499.99,
                'allowed_placements' => $allPlacementCodes, // All allowed
                'is_active' => true,
            ],
        ];

        foreach ($packages as $data) {
            BannerPackage::updateOrCreate(
                ['name' => $data['name']],
                $data
            );
        }

        // 3. Seed Banners
        $user = User::first(); 
        $premiumPackage = BannerPackage::where('name', 'Premium Dominance')->first();
        
        // Helper to create banner
        $createBanner = function($placementCode, $count, $prefix) use ($user, $premiumPackage) {
            $placement = BannerPlacement::where('code', $placementCode)->first();
            if (!$placement || !$premiumPackage) return;

            for ($i = 1; $i <= $count; $i++) {
                $startDate = Carbon::now()->subDays(rand(0, 5));
                $endDate = (clone $startDate)->addDays($premiumPackage->duration_days);

                Banner::create([
                    'name' => "{$prefix} Banner $i",
                    'image' => "https://placehold.co/{$placement->width}x{$placement->height}/png?text={$prefix}+{$i}",
                    'user_id' => $user ? $user->id : null,
                    'banner_package_id' => $premiumPackage->id,
                    'banner_placement_id' => $placement->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'approved',
                    'is_active' => true,
                    'redirect_url' => 'https://example.com/promo',
                    'impressions' => rand(100, 10000),
                    'clicks' => rand(10, 500),
                    'duration' => $premiumPackage->duration_days,
                    'level' => 'standard', 
                    'image_orientation' => ($placement->width > $placement->height) ? 'landscape' : 'portrait',
                ]);
            }
        };

        // Landing Page Specifics
        $createBanner('HOME_HERO', 4, 'Home Hero');
        $createBanner('HOME_MIDDLE', 3, 'Home Middle');
        $createBanner('HOME_FOOTER', 1, 'Home Footer');
        $createBanner('APP_SPLASH', 1, 'App Splash');

        // Other Pages (City, Route Details, Route List)
        $otherPages = [
            'CITY_MIDDLE' => 2,
            'CITY_FOOTER' => 1,
            'ROUTE_DETAIL_MIDDLE' => 2,
            'ROUTE_DETAIL_FOOTER' => 1,
            'ROUTE_LIST_MIDDLE' => 2,
            'ROUTE_LIST_FOOTER' => 1,
        ];

        foreach ($otherPages as $code => $count) {
            $createBanner($code, $count, ucwords(str_replace('_', ' ', strtolower($code))));
        }
    }
}