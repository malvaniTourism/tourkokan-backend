<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Launch plans.
 *
 * Everyone is on `free` for the first year, with limits set high enough that a genuine
 * vendor never meets them — the point of enforcing now is that the ceiling exists and is
 * being measured, not that anyone is stopped.
 *
 * The paid tiers are seeded inactive. Prices in them are placeholders: real pricing waits
 * on the lead data the metering pipeline is collecting, because leads are what vendors
 * will actually be charged for (§9). Activating a tier is a data change.
 *
 * Idempotent.
 */
class PlanSeeder extends Seeder
{
    private array $plans = [
        [
            'code' => 'free', 'name' => 'Free', 'mr_name' => 'मोफत',
            'description' => 'Free listing for the launch year. All core features included.',
            'price' => 0, 'billing_period' => 'free', 'is_active' => true, 'sort_order' => 0,
            'limits' => [
                'max_sites'              => 5,
                'max_products'           => 100,
                'max_images_per_product' => 10,
                'featured_slots'         => 0,   // featuring stays an editorial decision
            ],
        ],
        [
            'code' => 'starter', 'name' => 'Starter', 'mr_name' => 'स्टार्टर',
            'description' => 'For a growing business with several outlets.',
            'price' => 499, 'billing_period' => 'monthly', 'is_active' => false, 'sort_order' => 1,
            'limits' => [
                'max_sites'              => 15,
                'max_products'           => 500,
                'max_images_per_product' => 15,
                'featured_slots'         => 2,
            ],
        ],
        [
            'code' => 'growth', 'name' => 'Growth', 'mr_name' => 'ग्रोथ',
            'description' => 'Unlimited listings and priority placement.',
            'price' => 1499, 'billing_period' => 'monthly', 'is_active' => false, 'sort_order' => 2,
            'limits' => [
                'max_sites'              => null,   // null = unlimited
                'max_products'           => null,
                'max_images_per_product' => 25,
                'featured_slots'         => 10,
            ],
        ],
    ];

    public function run(): void
    {
        foreach ($this->plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }

        $this->command->info('Seeded ' . count($this->plans) . ' plans (free active, paid tiers inactive).');
    }
}
