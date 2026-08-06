<?php

namespace Database\Seeders;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the product taxonomy and wires it to the existing site-category tree.
 *
 * `booking_type` is set correctly here even though nothing reads it yet — that is the whole
 * point of R2: when the availability calendar lands, the taxonomy is already right and
 * needs no re-seed. See docs/VENDOR_PRODUCTS_DESIGN.md §3.
 *
 * Idempotent — safe to re-run.
 */
class ProductCategorySeeder extends Seeder
{
    /**
     * code => [name, mr_name, booking_type, attribute_schema]
     */
    private array $productCategories = [
        'room_night' => [
            'Room Night', 'रूम प्रति रात्र', 'date_range',
            [
                'occupancy'  => ['type' => 'int',  'label' => 'Max guests', 'mr_label' => 'जास्तीत जास्त पाहुणे', 'required' => true, 'min' => 1, 'max' => 20],
                'ac'         => ['type' => 'bool', 'label' => 'Air conditioned', 'mr_label' => 'वातानुकूलित'],
                'meal_plan'  => ['type' => 'enum', 'label' => 'Meal plan', 'mr_label' => 'जेवण योजना', 'options' => ['EP', 'CP', 'MAP', 'AP']],
                'bed_type'   => ['type' => 'enum', 'label' => 'Bed type', 'options' => ['Single', 'Double', 'Twin', 'Queen', 'King']],
                'check_in'   => ['type' => 'time', 'label' => 'Check-in time'],
                'check_out'  => ['type' => 'time', 'label' => 'Check-out time'],
            ],
        ],
        'stay_package' => [
            'Stay Package', 'निवास पॅकेज', 'date_range',
            [
                'nights'     => ['type' => 'int',   'label' => 'Nights', 'required' => true, 'min' => 1, 'max' => 30],
                'guests'     => ['type' => 'int',   'label' => 'Guests included', 'required' => true, 'min' => 1],
                'includes'   => ['type' => 'multi', 'label' => 'Includes', 'options' => ['Breakfast', 'Lunch', 'Dinner', 'Sightseeing', 'Pickup', 'Boating']],
            ],
        ],
        'menu_item' => [
            'Menu Item', 'मेनू आयटम', 'none',
            [
                'veg_type'       => ['type' => 'enum',  'label' => 'Type', 'mr_label' => 'प्रकार', 'required' => true, 'options' => ['Veg', 'Non-veg', 'Egg', 'Vegan']],
                'cuisine'        => ['type' => 'multi', 'label' => 'Cuisine', 'options' => ['Malvani', 'Konkani', 'Maharashtrian', 'South Indian', 'Chinese', 'Continental']],
                'spice_level'    => ['type' => 'enum',  'label' => 'Spice level', 'options' => ['Mild', 'Medium', 'Hot', 'Extra hot']],
                'serves'         => ['type' => 'int',   'label' => 'Serves (persons)', 'min' => 1, 'max' => 20],
                'is_signature'   => ['type' => 'bool',  'label' => 'Signature dish'],
            ],
        ],
        'thali' => [
            'Thali', 'थाळी', 'none',
            [
                'veg_type' => ['type' => 'enum',  'label' => 'Type', 'required' => true, 'options' => ['Veg', 'Non-veg', 'Seafood']],
                'items'    => ['type' => 'multi', 'label' => 'Items included', 'options' => ['Rice', 'Bhakri', 'Chapati', 'Solkadhi', 'Fish curry', 'Sabzi', 'Dessert', 'Papad', 'Pickle']],
                'unlimited' => ['type' => 'bool', 'label' => 'Unlimited servings'],
            ],
        ],
        'activity_ticket' => [
            'Activity Ticket', 'उपक्रम तिकीट', 'slot',
            [
                'duration_min' => ['type' => 'int',  'label' => 'Duration (minutes)', 'required' => true, 'min' => 5, 'max' => 1440],
                'min_age'      => ['type' => 'int',  'label' => 'Minimum age', 'min' => 0, 'max' => 100],
                'safety_gear'  => ['type' => 'bool', 'label' => 'Safety gear provided'],
                'instructor'   => ['type' => 'bool', 'label' => 'Instructor included'],
                'difficulty'   => ['type' => 'enum', 'label' => 'Difficulty', 'options' => ['Beginner', 'Intermediate', 'Advanced']],
            ],
        ],
        'equipment_rental' => [
            'Equipment Rental', 'उपकरण भाडे', 'slot',
            [
                'equipment_type' => ['type' => 'string', 'label' => 'Equipment', 'required' => true, 'max' => 100],
                'deposit_required' => ['type' => 'bool', 'label' => 'Deposit required'],
                'min_hours'      => ['type' => 'int',  'label' => 'Minimum hours', 'min' => 1],
            ],
        ],
        'alphonso_mango' => [
            'Alphonso Mango', 'हापूस आंबा', 'quantity',
            [
                'grade'         => ['type' => 'enum', 'label' => 'Grade', 'mr_label' => 'दर्जा', 'required' => true, 'options' => ['A', 'B', 'C']],
                'dozen_count'   => ['type' => 'int',  'label' => 'Mangoes per box', 'required' => true, 'min' => 1, 'max' => 100],
                'harvest_month' => ['type' => 'enum', 'label' => 'Harvest month', 'options' => ['March', 'April', 'May', 'June']],
                'organic'       => ['type' => 'bool', 'label' => 'Organically grown'],
            ],
        ],
        'kokum_product' => [
            'Kokum Product', 'कोकम उत्पादन', 'quantity',
            [
                'form'        => ['type' => 'enum', 'label' => 'Form', 'required' => true, 'options' => ['Syrup', 'Agal', 'Dried rind', 'Butter']],
                'net_weight'  => ['type' => 'string', 'label' => 'Net weight / volume', 'max' => 50],
                'preservative_free' => ['type' => 'bool', 'label' => 'Preservative free'],
            ],
        ],
        'cashew' => [
            'Cashew', 'काजू', 'quantity',
            [
                'grade'     => ['type' => 'enum',   'label' => 'Grade', 'required' => true, 'options' => ['W180', 'W210', 'W240', 'W320', 'Broken']],
                'roasted'   => ['type' => 'bool',   'label' => 'Roasted'],
                'flavour'   => ['type' => 'enum',   'label' => 'Flavour', 'options' => ['Plain', 'Salted', 'Masala', 'Peri peri']],
                'net_weight' => ['type' => 'string', 'label' => 'Net weight', 'max' => 50],
            ],
        ],
        'handicraft' => [
            'Handicraft', 'हस्तकला', 'quantity',
            [
                'material'   => ['type' => 'multi',  'label' => 'Material', 'options' => ['Wood', 'Clay', 'Bamboo', 'Cloth', 'Shell', 'Metal']],
                'handmade'   => ['type' => 'bool',   'label' => 'Handmade'],
                'dimensions' => ['type' => 'string', 'label' => 'Dimensions', 'max' => 100],
            ],
        ],
        'tour_package' => [
            'Tour Package', 'सहल पॅकेज', 'date_range',
            [
                'days'       => ['type' => 'int',   'label' => 'Days', 'required' => true, 'min' => 1, 'max' => 30],
                'nights'     => ['type' => 'int',   'label' => 'Nights', 'min' => 0, 'max' => 30],
                'group_size' => ['type' => 'int',   'label' => 'Max group size', 'min' => 1],
                'includes'   => ['type' => 'multi', 'label' => 'Includes', 'options' => ['Transport', 'Stay', 'Meals', 'Guide', 'Tickets']],
                'itinerary'  => ['type' => 'text',  'label' => 'Itinerary', 'max' => 5000],
            ],
        ],
        'guide_service' => [
            'Guide Service', 'मार्गदर्शक सेवा', 'slot',
            [
                'languages'    => ['type' => 'multi', 'label' => 'Languages', 'options' => ['Marathi', 'Hindi', 'English', 'Konkani']],
                'duration_min' => ['type' => 'int',   'label' => 'Duration (minutes)', 'min' => 30],
                'group_size'   => ['type' => 'int',   'label' => 'Max group size', 'min' => 1],
            ],
        ],
    ];

    /**
     * Site-category code => product-category codes permitted under it.
     *
     * Keyed on the parent site category; every child category inherits the same set.
     */
    private array $allowedBySiteCategory = [
        'accomodation'     => ['room_night', 'stay_package'],
        'food'             => ['menu_item', 'thali'],
        'sport_activity'   => ['activity_ticket', 'equipment_rental'],
        'tourist_interest' => ['handicraft', 'alphonso_mango', 'kokum_product', 'cashew', 'guide_service', 'tour_package'],
        'kokan_view'       => ['activity_ticket', 'guide_service'],
    ];

    public function run(): void
    {
        $codeToId = [];

        foreach ($this->productCategories as $code => [$name, $mrName, $bookingType, $schema]) {
            $category = ProductCategory::updateOrCreate(
                ['code' => $code],
                [
                    'name'             => $name,
                    'mr_name'          => $mrName,
                    'slug'             => Str::slug($name),
                    'attribute_schema' => $schema,
                    'booking_type'     => $bookingType,
                    'status'           => true,
                    'sort_order'       => 0,
                ]
            );

            $codeToId[$code] = $category->id;
        }

        $this->command->info('Seeded ' . count($codeToId) . ' product categories.');

        $links = 0;

        foreach ($this->allowedBySiteCategory as $siteCategoryCode => $productCodes) {
            $parent = Category::where('code', $siteCategoryCode)->first();

            if (!$parent) {
                $this->command->warn("Site category '{$siteCategoryCode}' not found — skipped.");
                continue;
            }

            // the parent plus every child inherit the same permitted set
            $categoryIds = Category::where('id', $parent->id)
                ->orWhere('parent_id', $parent->id)
                ->pluck('id');

            foreach ($categoryIds as $categoryId) {
                foreach ($productCodes as $productCode) {
                    if (!isset($codeToId[$productCode])) {
                        continue;
                    }

                    AllowedProductCategory::updateOrCreate(
                        [
                            'category_id'         => $categoryId,
                            'product_category_id' => $codeToId[$productCode],
                        ],
                        ['is_required' => false, 'max_products' => null]
                    );

                    $links++;
                }
            }
        }

        $this->command->info("Seeded {$links} site-category → product-category links.");
    }
}
