<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleRequest;
use App\Services\PlanService;
use Tests\ApiTestCase;

/**
 * Captures real request/response pairs for docs/admin-api-integration.md.
 *
 * Documentation written from memory drifts; this exercises each endpoint against the real
 * application and dumps what it actually returns, so the payloads in the guide are
 * observed rather than imagined.
 *
 * Not a behavioural test — it asserts only that each call succeeded, and writes its output
 * to a scratch file the doc generator reads.
 */
class AdminApiDocCaptureTest extends ApiTestCase
{

    private array $captured = [];
    private User $admin;
    private User $vendor;

    public function test_capture_admin_api_samples(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->admin  = $this->userWithRole('admin');
        $this->vendor = $this->userWithRole('vendor');
        app(PlanService::class)->enrolOnFree($this->vendor);

        // ── fixtures ────────────────────────────────────────────────────────────
        $siteCategory = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल रूम', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $roomNight = ProductCategory::create([
            'name' => 'Room Night', 'mr_name' => 'रूम प्रति रात्र',
            'code' => 'room_night', 'slug' => 'room-night', 'booking_type' => 'date_range',
            'attribute_schema' => [
                'occupancy' => ['type' => 'int', 'label' => 'Max guests', 'mr_label' => 'पाहुणे',
                                'required' => true, 'min' => 1, 'max' => 20],
                'ac'        => ['type' => 'bool', 'label' => 'Air conditioned'],
                'meal_plan' => ['type' => 'enum', 'label' => 'Meal plan',
                                'options' => ['EP', 'CP', 'MAP', 'AP']],
            ],
        ]);

        AllowedProductCategory::create([
            'category_id' => $siteCategory->id, 'product_category_id' => $roomNight->id,
            'max_products' => 50,
        ]);

        $site = Site::create([
            'name' => 'Sagar Resort Tarkarli',
            'description' => 'A sea-facing resort in Tarkarli with AC and non-AC rooms.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'is_primary' => true, 'latitude' => 16.0512, 'longitude' => 73.4680, 'pin_code' => '416606',
        ]);
        $site->categories()->attach($siteCategory->id);

        $product = Product::create([
            'site_id' => $site->id, 'product_category_id' => $roomNight->id,
            'name' => 'Deluxe Sea View Room', 'slug' => 'deluxe-sea-view-room',
            'description' => 'Sea-facing room with a private balcony.',
            'attributes' => ['occupancy' => 3, 'ac' => true, 'meal_plan' => 'CP'],
            'base_price' => 2400, 'unit' => 'per_night', 'currency' => 'INR',
            'hsn_code' => '996311', 'tax_rate' => 12, 'status' => 'pending',
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Standard', 'sku' => 'DLX-STD',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);

        // ── product moderation ──────────────────────────────────────────────────
        $this->grab('pendingProducts', []);
        $this->grab('listAllProducts', ['status' => 'pending']);
        $this->grab('getProductAdmin', ['id' => $product->id]);
        $this->grab('approveProduct', ['id' => $product->id]);
        $this->grab('featureProduct', ['id' => $product->id, 'is_featured' => true]);

        // a second product so reject can be shown without un-approving the first
        $rejectable = Product::create([
            'site_id' => $site->id, 'product_category_id' => $roomNight->id,
            'name' => 'Garden Cottage', 'slug' => 'garden-cottage',
            'base_price' => 1800, 'status' => 'pending',
        ]);
        ProductVariant::create(['product_id' => $rejectable->id, 'name' => 'Standard',
                                'price' => 1800, 'is_default' => true, 'status' => true]);
        $this->grab('rejectProduct', ['id' => $rejectable->id,
                                      'rejection_reason' => 'Images do not match the description.']);

        // failure sample — approving a listing whose site is not live
        $offline = Site::create([
            'name' => 'Pending Business', 'description' => 'A business still awaiting review by an admin.',
            'user_id' => $this->vendor->id, 'status' => false, 'submission_status' => 'pending',
            'latitude' => 16.06, 'longitude' => 73.47,
        ]);
        $offline->categories()->attach($siteCategory->id);
        $blocked = Product::create([
            'site_id' => $offline->id, 'product_category_id' => $roomNight->id,
            'name' => 'Too Early', 'slug' => 'too-early', 'base_price' => 900, 'status' => 'pending',
        ]);
        $this->grab('approveProduct', ['id' => $blocked->id], 'approveProduct — site not live', false);

        // ── taxonomy ────────────────────────────────────────────────────────────
        $this->grab('listProductCategories', []);
        $this->grab('getProductCategory', ['id' => $roomNight->id]);
        $this->grab('addProductCategory', [
            'name' => 'Alphonso Mango', 'mr_name' => 'हापूस आंबा',
            'code' => 'alphonso_mango', 'booking_type' => 'quantity', 'status' => true,
            'attribute_schema' => [
                'grade'       => ['type' => 'enum', 'label' => 'Grade', 'mr_label' => 'दर्जा',
                                  'required' => true, 'options' => ['A', 'B', 'C']],
                'dozen_count' => ['type' => 'int', 'label' => 'Mangoes per box',
                                  'required' => true, 'min' => 1, 'max' => 100],
                'organic'     => ['type' => 'bool', 'label' => 'Organically grown'],
            ],
        ]);
        $this->grab('updateProductCategory', ['id' => $roomNight->id, 'sort_order' => 1]);
        $this->grab('setAllowedProductCategories', [
            'category_id' => $siteCategory->id,
            'allowed' => [['product_category_id' => $roomNight->id, 'max_products' => 50]],
        ]);

        // failure samples
        $this->grab('addProductCategory', [
            'name' => 'Bad Schema', 'code' => 'bad_schema',
            'attribute_schema' => ['price' => ['type' => 'decimal', 'label' => 'Price']],
        ], 'addProductCategory — reserved key refused', false);

        $this->grab('addProductCategory', [
            'name' => 'Missing code',
        ], 'addProductCategory — validation failure', false);

        // ── plans ───────────────────────────────────────────────────────────────
        $growth = Plan::where('code', 'growth')->first();
        $this->grab('listPlans', []);
        $this->grab('listSubscriptions', []);
        $this->grab('vendorUsageReport', ['user_id' => $this->vendor->id]);
        $this->grab('assignPlan', ['user_id' => $this->vendor->id, 'plan_id' => $growth->id, 'months' => 12]);
        $this->grab('addPlan', [
            'code' => 'pro', 'name' => 'Pro', 'price' => 999, 'billing_period' => 'monthly',
            'is_active' => false,
            'limits' => ['max_sites' => 20, 'max_products' => 1000,
                         'max_images_per_product' => 20, 'featured_slots' => 5],
        ]);
        $this->grab('updatePlan', ['id' => $growth->id, 'sort_order' => 3]);

        // ── changed existing endpoints ──────────────────────────────────────────
        $freshSite = Site::create([
            'name' => 'Kokan Kirana', 'description' => 'A village grocery shop listed by its owner.',
            'user_id' => User::factory()->create()->id, 'status' => false, 'submission_status' => 'pending',
            'latitude' => 16.07, 'longitude' => 73.48,
        ]);
        $this->grab('approveSite', ['id' => $freshSite->id], 'approveSite — auto-marks first site primary');

        $newUser    = User::factory()->create();
        $vendorRole = \App\Models\Roles::firstOrCreate(['code' => 'vendor'], ['name' => 'Vendor']);
        $roleReq    = UserRoleRequest::create([
            'user_id' => $newUser->id, 'role_id' => $vendorRole->id, 'status' => 'pending',
            'reason' => 'I run a resort in Tarkarli and want to list my rooms.',
        ]);
        $this->grab('approveRoleRequest', ['id' => $roleReq->id],
                    'approveRoleRequest — also enrols vendors on the free plan');

        @mkdir(sys_get_temp_dir() . '/tk-docs', 0777, true);
        $out = sys_get_temp_dir() . '/tk-docs/admin_samples.json';
        file_put_contents($out, json_encode($this->captured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertNotEmpty($this->captured);
    }

    private function grab(string $endpoint, array $payload, ?string $label = null, bool $expectSuccess = true): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson("/admin/v2/{$endpoint}", $payload);
        $json     = $response->json();

        if ($expectSuccess && ($json['success'] ?? false) !== true) {
            $this->fail("{$endpoint} failed while capturing docs: " . json_encode($json['message'] ?? $json));
        }

        $this->captured[] = [
            'label'    => $label ?? $endpoint,
            'endpoint' => $endpoint,
            'status'   => $response->getStatusCode(),
            'request'  => $payload,
            'response' => $json,
        ];
    }
}
