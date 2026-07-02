<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Banner;
use App\Models\BannerPackage;
use App\Models\BannerPlacement;
use App\Models\User;
use Carbon\Carbon;

/**
 * Seeds the banner advertising system: placements, packages and placeholder
 * "Ad Space" banners for every sellable slot.
 *
 * Pricing rationale and calculations: docs/ADVERTISING_PRICING.md
 * Idempotent — safe to re-run (updateOrCreate everywhere).
 */
class BannerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
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
        // Placement groups — see docs/ADVERTISING_PRICING.md §4
        $allPlacementCodes = array_column($placements, 'code');
        $innerPlacements = [
            'CITY_MIDDLE', 'CITY_FOOTER',
            'ROUTE_DETAIL_MIDDLE', 'ROUTE_DETAIL_FOOTER',
            'ROUTE_LIST_MIDDLE', 'ROUTE_LIST_FOOTER',
        ];
        $homeSecondary = ['HOME_MIDDLE', 'HOME_FOOTER'];

        $packages = [
            // --- Phase 1: launch rate card (active) ---
            [
                'name' => 'Starter',
                'duration_days' => 7,
                'price' => 499.00,
                'allowed_placements' => $innerPlacements,
                'is_active' => true,
            ],
            [
                'name' => 'Growth',
                'duration_days' => 30,
                'price' => 1499.00,
                'allowed_placements' => array_merge($innerPlacements, $homeSecondary),
                'is_active' => true,
            ],
            [
                'name' => 'Spotlight',
                'duration_days' => 30,
                'price' => 3999.00,
                'allowed_placements' => $allPlacementCodes,
                'is_active' => true,
            ],
            [
                'name' => 'Season Pass',
                'duration_days' => 90,
                'price' => 9999.00,
                'allowed_placements' => $allPlacementCodes,
                'is_active' => true,
            ],

            // --- Phase 2: growth rate card (inactive until ~10-15k MAU) ---
            [
                'name' => 'Basic Starter',
                'duration_days' => 7,
                'price' => 2999.00,
                'allowed_placements' => ['HOME_MIDDLE', 'CITY_MIDDLE', 'ROUTE_LIST_MIDDLE', 'ROUTE_DETAIL_MIDDLE'],
                'is_active' => false,
            ],
            [
                'name' => 'Standard Growth',
                'duration_days' => 30,
                'price' => 9999.99,
                'allowed_placements' => ['HOME_HERO', 'HOME_FOOTER', 'CITY_FOOTER', 'ROUTE_LIST_FOOTER', 'ROUTE_DETAIL_FOOTER'],
                'is_active' => false,
            ],
            [
                'name' => 'Premium Dominance',
                'duration_days' => 90,
                'price' => 21999.99,
                'allowed_placements' => $allPlacementCodes,
                'is_active' => false,
            ],
        ];

        foreach ($packages as $data) {
            BannerPackage::updateOrCreate(
                ['name' => $data['name']],
                $data
            );
        }

        // 3. Seed placeholder "Ad Space" banners — one per sellable slot,
        // linked to the cheapest package that can book that placement.
        $user = User::first();
        $starter = BannerPackage::where('name', 'Starter')->first();
        $growth = BannerPackage::where('name', 'Growth')->first();
        $spotlight = BannerPackage::where('name', 'Spotlight')->first();

        // placement code => [slot count, package for the placeholder]
        $slots = [
            'HOME_HERO' => [4, $spotlight],
            'HOME_MIDDLE' => [3, $growth],
            'HOME_FOOTER' => [1, $growth],
            'APP_SPLASH' => [1, $spotlight],
            'CITY_MIDDLE' => [2, $starter],
            'CITY_FOOTER' => [1, $starter],
            'ROUTE_DETAIL_MIDDLE' => [2, $starter],
            'ROUTE_DETAIL_FOOTER' => [1, $starter],
            'ROUTE_LIST_MIDDLE' => [2, $starter],
            'ROUTE_LIST_FOOTER' => [1, $starter],
        ];

        foreach ($slots as $code => [$count, $package]) {
            $placement = BannerPlacement::where('code', $code)->first();
            if (!$placement || !$package) {
                continue;
            }

            $level = 'middle';
            if (in_array($code, ['HOME_HERO', 'APP_SPLASH'])) {
                $level = 'carousel';
            } elseif (str_ends_with($code, '_FOOTER')) {
                $level = 'footer';
            }

            for ($i = 1; $i <= $count; $i++) {
                $startDate = Carbon::now();
                $endDate = (clone $startDate)->addDays($package->duration_days);

                Banner::updateOrCreate(
                    ['name' => "Ad Space - {$code} #{$i}"],
                    [
                        'image' => "https://placehold.co/{$placement->width}x{$placement->height}/png?text=Advertise+Here",
                        'user_id' => $user ? $user->id : null,
                        'banner_package_id' => $package->id,
                        'banner_placement_id' => $placement->id,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        // banners.status is boolean (tinyint) — see docs/ADVERTISING_PRICING.md §9
                        'status' => true,
                        'is_active' => true,
                        'redirect_url' => 'https://tourkokan.com/#Contact',
                        'impressions' => 0,
                        'clicks' => 0,
                        'duration' => $package->duration_days,
                        'level' => $level,
                        'image_orientation' => ($placement->width > $placement->height) ? 'landscape' : 'potrait',
                    ]
                );
            }
        }
    }
}
