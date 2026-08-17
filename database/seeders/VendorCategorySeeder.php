<?php

namespace Database\Seeders;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Opens the platform to vendor businesses beyond tourism.
 *
 * The original category tree describes tourist *places* — Accommodation, Food, Kokan View,
 * Emergency, Government. That covers hotels and restaurants, but a tour operator, a
 * carpenter, an electrician or a village shop had nowhere to register, and `Transportation`
 * holds infrastructure (Airport, Railway Station, MSRTC) rather than services for hire.
 *
 * This seeder adds three business-facing branches and the product categories that go with
 * them, then wires the whitelist so each kind of vendor can only list what makes sense.
 *
 * Runs after ProductCategorySeeder — it links to categories that seeder creates.
 * Idempotent; safe to re-run.
 */
class VendorCategorySeeder extends Seeder
{
    /**
     * New site categories: parent code => [name, mr_name, [child => mr_child]]
     */
    private array $siteCategories = [
        'tour_travel' => ['Tour & Travel', 'सहल आणि प्रवास', [
            'Tour Operator'    => 'टूर ऑपरेटर',
            'Travel Agency'    => 'ट्रॅव्हल एजन्सी',
            'Taxi Service'     => 'टॅक्सी सेवा',
            'Boat Operator'    => 'बोट सेवा',
            'Vehicle Rental'   => 'वाहन भाडे',
            'Tour Guide'       => 'गाईड',
        ]],
        'local_service' => ['Local Services', 'स्थानिक सेवा', [
            'Carpenter'          => 'सुतार',
            'Electrician'        => 'इलेक्ट्रिशियन',
            'Plumber'            => 'प्लंबर',
            'Mason'              => 'गवंडी',
            'Painter'            => 'रंगारी',
            'Vehicle Mechanic'   => 'मेकॅनिक',
            'Tailor'             => 'शिंपी',
            'Salon & Barber'     => 'सलून',
            'Photographer'       => 'फोटोग्राफर',
            'Catering Service'   => 'केटरिंग',
            'Event Decorator'    => 'सजावट',
            'Appliance Repair'   => 'उपकरण दुरुस्ती',
        ]],
        'shopping' => ['Shopping', 'खरेदी', [
            'Grocery Store'    => 'किराणा दुकान',
            'Bakery'           => 'बेकरी',
            'Sweet Shop'       => 'मिठाई दुकान',
            'Medical Store'    => 'मेडिकल',
            'Hardware Store'   => 'हार्डवेअर',
            'Clothing Store'   => 'कपडे दुकान',
            'Handicraft Shop'  => 'हस्तकला दुकान',
            'Fish Market'      => 'मासळी बाजार',
            'Farm Produce'     => 'शेतमाल',
        ]],
    ];

    /**
     * code => [name, mr_name, booking_type, attribute_schema]
     */
    private array $productCategories = [
        'vehicle_rental' => ['Vehicle Rental', 'वाहन भाडे', 'slot', [
            'vehicle_type'     => ['type' => 'enum', 'label' => 'Vehicle', 'mr_label' => 'वाहन', 'required' => true,
                                   'options' => ['Bike', 'Scooter', 'Car', 'SUV', 'Tempo Traveller', 'Bus']],
            'with_driver'      => ['type' => 'bool', 'label' => 'Driver included'],
            'fuel_included'    => ['type' => 'bool', 'label' => 'Fuel included'],
            'min_hours'        => ['type' => 'int',  'label' => 'Minimum hours', 'min' => 1, 'max' => 720],
            'license_required' => ['type' => 'bool', 'label' => 'Driving licence required'],
        ]],
        'taxi_transfer' => ['Taxi / Transfer', 'टॅक्सी सेवा', 'slot', [
            'vehicle_type' => ['type' => 'enum', 'label' => 'Vehicle', 'required' => true,
                               'options' => ['Hatchback', 'Sedan', 'SUV', 'Tempo Traveller']],
            'seats'        => ['type' => 'int',  'label' => 'Seats', 'required' => true, 'min' => 1, 'max' => 50],
            'ac'           => ['type' => 'bool', 'label' => 'Air conditioned'],
            'route_type'   => ['type' => 'enum', 'label' => 'Route', 'options' => ['Local', 'Outstation', 'Airport transfer', 'Railway transfer']],
        ]],
        'boat_ride' => ['Boat Ride', 'बोट राईड', 'slot', [
            'boat_type'    => ['type' => 'enum', 'label' => 'Boat type', 'options' => ['Rowing', 'Motor', 'Speed boat', 'House boat', 'Dolphin trip']],
            'capacity'     => ['type' => 'int',  'label' => 'Capacity', 'required' => true, 'min' => 1, 'max' => 100],
            'life_jackets' => ['type' => 'bool', 'label' => 'Life jackets provided'],
            'duration_min' => ['type' => 'int',  'label' => 'Duration (minutes)', 'min' => 5, 'max' => 1440],
        ]],
        'service_call' => ['Service Visit', 'सेवा भेट', 'none', [
            'service_type'        => ['type' => 'string', 'label' => 'Work offered', 'mr_label' => 'काम', 'required' => true, 'max' => 120],
            'charge_basis'        => ['type' => 'enum',   'label' => 'Charged by', 'required' => true,
                                      'options' => ['Per visit', 'Per hour', 'Per day', 'Per sq.ft', 'Per job']],
            'experience_years'    => ['type' => 'int',    'label' => 'Years of experience', 'min' => 0, 'max' => 70],
            'on_site'             => ['type' => 'bool',   'label' => 'Visits customer location'],
            'emergency_available' => ['type' => 'bool',   'label' => 'Available for emergencies'],
            'warranty_days'       => ['type' => 'int',    'label' => 'Work guarantee (days)', 'min' => 0, 'max' => 3650],
        ]],
        'repair_service' => ['Repair Service', 'दुरुस्ती सेवा', 'none', [
            'appliance_type'  => ['type' => 'multi',  'label' => 'Repairs', 'required' => true,
                                  'options' => ['Fridge', 'AC', 'Washing machine', 'TV', 'Mobile', 'Two-wheeler', 'Car', 'Fan', 'Water pump', 'Inverter']],
            'home_visit'      => ['type' => 'bool',   'label' => 'Home visit available'],
            'pickup_drop'     => ['type' => 'bool',   'label' => 'Pickup and drop'],
            'warranty_days'   => ['type' => 'int',    'label' => 'Repair warranty (days)', 'min' => 0, 'max' => 3650],
        ]],
        'retail_item' => ['Shop Item', 'दुकानातील वस्तू', 'quantity', [
            'brand'          => ['type' => 'string', 'label' => 'Brand', 'max' => 80],
            'net_weight'     => ['type' => 'string', 'label' => 'Weight / size', 'max' => 50],
            'made_in_kokan'  => ['type' => 'bool',   'label' => 'Made in Kokan'],
            'home_delivery'  => ['type' => 'bool',   'label' => 'Home delivery available'],
        ]],
        'farm_produce' => ['Farm Produce', 'शेतमाल', 'quantity', [
            'produce_type'  => ['type' => 'string', 'label' => 'Produce', 'required' => true, 'max' => 80],
            'organic'       => ['type' => 'bool',   'label' => 'Organically grown'],
            'harvest_month' => ['type' => 'enum',   'label' => 'Harvest month',
                                'options' => ['January', 'February', 'March', 'April', 'May', 'June',
                                              'July', 'August', 'September', 'October', 'November', 'December']],
            'net_weight'    => ['type' => 'string', 'label' => 'Sold in', 'max' => 50],
        ]],
        'catering_package' => ['Catering Package', 'केटरिंग पॅकेज', 'date_range', [
            'cuisine'    => ['type' => 'multi', 'label' => 'Cuisine', 'options' => ['Malvani', 'Konkani', 'Maharashtrian', 'Punjabi', 'South Indian', 'Chinese']],
            'veg_type'   => ['type' => 'enum',  'label' => 'Type', 'required' => true, 'options' => ['Veg', 'Non-veg', 'Both']],
            'min_guests' => ['type' => 'int',   'label' => 'Minimum guests', 'required' => true, 'min' => 1],
            'includes'   => ['type' => 'multi', 'label' => 'Includes', 'options' => ['Cooking', 'Serving staff', 'Utensils', 'Tables & chairs', 'Cleanup']],
        ]],
    ];

    /**
     * Site category code => product category codes permitted under it and all its children.
     */
    private array $allowedBySiteCategory = [
        'tour_travel'   => ['tour_package', 'guide_service', 'vehicle_rental', 'taxi_transfer', 'boat_ride'],
        'local_service' => ['service_call', 'repair_service'],
        'shopping'      => ['retail_item', 'farm_produce'],
    ];

    /**
     * Narrower sets for specific children, replacing the parent's list.
     *
     * Without these a carpenter is offered "Catering Package" and a taxi service is offered
     * "Boat Ride" — harmless to the data, but it makes the app's category picker noisy for
     * the vendor. Keyed by category code.
     */
    private array $allowedByChildCategory = [
        'catering_service'  => ['catering_package', 'service_call'],
        'appliance_repair'  => ['repair_service'],
        'vehicle_mechanic'  => ['repair_service', 'service_call'],
        'taxi_service'      => ['taxi_transfer', 'vehicle_rental'],
        'vehicle_rental'    => ['vehicle_rental'],
        'boat_operator'     => ['boat_ride'],
        'tour_guide'        => ['guide_service', 'tour_package'],
        'handicraft_shop'   => ['handicraft', 'retail_item'],
        'farm_produce'      => ['farm_produce', 'alphonso_mango', 'kokum_product', 'cashew'],
        'fish_market'       => ['farm_produce', 'retail_item'],
        'medical_store'     => ['retail_item'],
        'bakery'            => ['retail_item'],
        'sweet_shop'        => ['retail_item'],
    ];

    public function run(): void
    {
        $this->seedSiteCategories();
        $this->seedProductCategories();
        $this->seedWhitelist();
    }

    private function seedSiteCategories(): void
    {
        $created = 0;

        foreach ($this->siteCategories as $code => [$name, $mrName, $children]) {
            $parent = Category::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'mr_name' => $mrName, 'icon' => '', 'status' => true]
            );
            $created++;

            foreach ($children as $childName => $childMrName) {
                Category::firstOrCreate(
                    ['code' => Str::snake(Str::lower($childName))],
                    [
                        'name'      => $childName,
                        'mr_name'   => $childMrName,
                        'parent_id' => $parent->id,
                        'icon'      => '',
                        'status'    => true,
                    ]
                );
                $created++;
            }
        }

        $this->command->info("Vendor site categories in place: {$created}.");
    }

    private function seedProductCategories(): void
    {
        foreach ($this->productCategories as $code => [$name, $mrName, $bookingType, $schema]) {
            ProductCategory::updateOrCreate(
                ['code' => $code],
                [
                    'name'             => $name,
                    'mr_name'          => $mrName,
                    'slug'             => Str::slug($name),
                    'attribute_schema' => $schema,
                    'booking_type'     => $bookingType,
                    'status'           => true,
                ]
            );
        }

        $this->command->info('Vendor product categories in place: ' . count($this->productCategories) . '.');
    }

    private function seedWhitelist(): void
    {
        $links      = 0;
        $productIds = ProductCategory::pluck('id', 'code');

        foreach ($this->allowedBySiteCategory as $siteCategoryCode => $parentCodes) {
            $parent = Category::where('code', $siteCategoryCode)->first();

            if (!$parent) {
                $this->command->warn("Site category '{$siteCategoryCode}' missing — skipped.");
                continue;
            }

            $categories = Category::where('id', $parent->id)
                ->orWhere('parent_id', $parent->id)
                ->get(['id', 'code']);

            foreach ($categories as $category) {
                // a child with its own entry replaces the parent's list entirely
                $codes = $this->allowedByChildCategory[$category->code] ?? $parentCodes;

                foreach ($codes as $code) {
                    if (!isset($productIds[$code])) {
                        $this->command->warn("Product category '{$code}' missing — run ProductCategorySeeder first.");
                        continue;
                    }

                    AllowedProductCategory::updateOrCreate(
                        ['category_id' => $category->id, 'product_category_id' => $productIds[$code]],
                        ['is_required' => false, 'max_products' => null]
                    );
                    $links++;
                }
            }
        }

        $this->command->info("Vendor site-category → product-category links: {$links}.");

        // Flag every registrable category (and its parent) so the app's "Register a
        // business" picker can filter on one field. Mirrors the migration's backfill so a
        // fresh seed leaves is_business correct. See docs/marketplace-backend-asks.md #4.
        if (Schema::hasColumn('categories', 'is_business')) {
            $leafIds = AllowedProductCategory::distinct()->pluck('category_id');
            Category::whereIn('id', $leafIds)->update(['is_business' => true]);

            $parentIds = Category::whereIn('id', $leafIds)->whereNotNull('parent_id')
                ->distinct()->pluck('parent_id');
            Category::whereIn('id', $parentIds)->update(['is_business' => true]);

            $this->command->info('Flagged is_business on registrable categories.');
        }
    }
}
