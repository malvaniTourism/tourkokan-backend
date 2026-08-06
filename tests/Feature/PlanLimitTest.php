<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Site;
use App\Models\User;
use App\Models\VendorSubscription;
use App\Services\PlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * Plans and quota enforcement.
 *
 * Listing is free for the launch year, so in practice these checks pass — the point is that
 * the enforcement point exists before vendors accumulate listings, because switching limits
 * on afterwards puts existing accounts retroactively over quota.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class PlanLimitTest extends ApiTestCase
{
    private User $vendor;
    private Site $site;
    private ProductCategory $productCategory;
    private Category $siteCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->vendor = $this->userWithRole('vendor');

        $this->siteCategory = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
            'icon' => '', 'status' => true,
        ]);

        $this->productCategory = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
        ]);

        AllowedProductCategory::create([
            'category_id' => $this->siteCategory->id,
            'product_category_id' => $this->productCategory->id,
        ]);

        $this->site = $this->makeSite();
    }

    private function makeSite(): Site
    {
        $site = Site::create([
            'name' => 'Outlet ' . uniqid(), 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $site->categories()->attach($this->siteCategory->id);

        return $site;
    }

    private function addProduct(string $name = null)
    {
        return $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addProduct', [
            'site_id' => $this->site->id,
            'product_category_id' => $this->productCategory->id,
            'name' => $name ?? 'Room ' . uniqid(),
            'base_price' => 2400,
        ]);
    }

    private function setLimit(string $key, ?int $value): void
    {
        $plan = Plan::where('code', 'free')->first();
        $plan->update(['limits' => array_merge($plan->limits ?? [], [$key => $value])]);
    }

    // ── Plan resolution ──────────────────────────────────────────────────────────

    public function test_a_vendor_without_a_subscription_falls_back_to_free(): void
    {
        // Degrade, never deny — a vendor predating the plans table must keep working.
        $plan = app(PlanService::class)->planFor($this->vendor);

        $this->assertSame('free', $plan->code);
    }

    public function test_approving_the_vendor_role_enrols_the_user_on_free(): void
    {
        $admin = $this->userWithRole('admin');
        $user  = User::factory()->create();

        $vendorRole = \App\Models\Roles::firstOrCreate(['code' => 'vendor'], ['name' => 'Vendor']);
        $request    = \App\Models\UserRoleRequest::create([
            'user_id' => $user->id, 'role_id' => $vendorRole->id, 'status' => 'pending',
        ]);

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')
                ->postJson('/admin/v2/approveRoleRequest', ['id' => $request->id])
        );

        $subscription = VendorSubscription::where('user_id', $user->id)->first();

        $this->assertNotNull($subscription);
        $this->assertSame('free', $subscription->plan->code);
        $this->assertNotNull($subscription->ends_at, 'the free period is dated, not indefinite');
    }

    public function test_enrolling_twice_does_not_stack_subscriptions(): void
    {
        $service = app(PlanService::class);

        $service->enrolOnFree($this->vendor);
        $service->enrolOnFree($this->vendor);

        $this->assertSame(1, VendorSubscription::where('user_id', $this->vendor->id)->count());
    }

    public function test_an_expired_subscription_falls_back_to_free_rather_than_locking_out(): void
    {
        $growth = Plan::where('code', 'growth')->first();

        VendorSubscription::create([
            'user_id' => $this->vendor->id, 'plan_id' => $growth->id,
            'starts_at' => now()->subYear(), 'ends_at' => now()->subDay(), 'status' => 'active',
        ]);

        $this->assertSame('free', app(PlanService::class)->planFor($this->vendor)->code);
    }

    // ── Enforcement ──────────────────────────────────────────────────────────────

    public function test_the_free_plan_limits_do_not_obstruct_a_normal_vendor(): void
    {
        // The launch ceiling is meant to be invisible.
        $this->assertApiSuccess($this->addProduct());
        $this->assertSame(1, Product::count());
    }

    public function test_the_product_quota_is_enforced(): void
    {
        $this->setLimit('max_products', 2);

        $this->assertApiSuccess($this->addProduct('One'));
        $this->assertApiSuccess($this->addProduct('Two'));
        $this->assertApiFailure($this->addProduct('Three'));

        $this->assertSame(2, Product::count());
    }

    public function test_the_quota_message_names_the_plan_and_the_number(): void
    {
        $this->setLimit('max_products', 1);
        $this->addProduct();

        $response = $this->addProduct();

        $this->assertStringContainsString('Free', $response->json('message'));
        $this->assertStringContainsString('1', $response->json('message'));
        $this->assertSame(1, $response->json('data.limit.limit'));
        $this->assertSame(1, $response->json('data.limit.used'));
    }

    public function test_a_null_limit_means_unlimited(): void
    {
        $this->setLimit('max_products', null);

        foreach (range(1, 5) as $i) {
            $this->assertApiSuccess($this->addProduct("Room {$i}"));
        }

        $this->assertSame(5, Product::count());
    }

    public function test_a_plan_missing_a_limit_key_stays_permissive(): void
    {
        // A plan seeded before a new quota existed must not lock vendors out of it.
        Plan::where('code', 'free')->update(['limits' => []]);

        $this->assertApiSuccess($this->addProduct());
    }

    public function test_the_site_quota_is_enforced(): void
    {
        $this->setLimit('max_sites', 1);   // the vendor already owns one

        $response = $this->actingAs($this->vendor, 'api')->postJson('/api/v2/addSite', [
            'name' => 'Second Outlet',
            'categories' => [$this->siteCategory->id],
            'description' => 'A second Kokan business listing used for testing purposes.',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);

        $this->assertApiFailure($response);
        $this->assertSame(1, Site::where('user_id', $this->vendor->id)->count());
    }

    public function test_the_per_product_image_quota_is_enforced(): void
    {
        Storage::fake();
        $this->setLimit('max_images_per_product', 2);

        $this->addProduct();
        $productId = Product::first()->id;

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->assertApiSuccess(
                $this->actingAs($this->vendor, 'api')->postJson('/api/v2/uploadProductMedia', [
                    'id' => $productId, 'image' => UploadedFile::fake()->image($name),
                ])
            );
        }

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/uploadProductMedia', [
                'id' => $productId, 'image' => UploadedFile::fake()->image('c.jpg'),
            ])
        );

        $this->assertSame(2, Product::find($productId)->gallery()->count());
    }

    public function test_quotas_are_counted_per_vendor_not_globally(): void
    {
        $this->setLimit('max_products', 1);
        $this->addProduct();

        // a second vendor still has their own allowance
        $other = $this->userWithRole('vendor');
        $otherSite = Site::create([
            'name' => 'Their Outlet', 'description' => 'Another Kokan business for testing purposes.',
            'user_id' => $other->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.1, 'longitude' => 73.5,
        ]);
        $otherSite->categories()->attach($this->siteCategory->id);

        $this->assertApiSuccess(
            $this->actingAs($other, 'api')->postJson('/api/v2/addProduct', [
                'site_id' => $otherSite->id,
                'product_category_id' => $this->productCategory->id,
                'name' => 'Their Room', 'base_price' => 1000,
            ])
        );
    }

    // ── Vendor-facing ────────────────────────────────────────────────────────────

    public function test_a_vendor_can_see_their_plan_and_usage(): void
    {
        $this->addProduct();

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/mySubscription')
        );

        $this->assertSame('free', $response->json('data.plan.code'));
        $this->assertSame(1, $response->json('data.usage.max_products.used'));
        $this->assertSame(100, $response->json('data.usage.max_products.limit'));
        $this->assertSame(99, $response->json('data.usage.max_products.remaining'));
    }

    public function test_only_active_plans_are_advertised(): void
    {
        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/listPlans')
        );

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertSame(['free'], $codes->all(), 'paid tiers stay hidden until pricing is settled');
    }

    // ── Admin ────────────────────────────────────────────────────────────────────

    public function test_an_admin_can_move_a_vendor_onto_another_plan(): void
    {
        $admin  = $this->userWithRole('admin');
        $growth = Plan::where('code', 'growth')->first();

        app(PlanService::class)->enrolOnFree($this->vendor);

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/assignPlan', [
                'user_id' => $this->vendor->id, 'plan_id' => $growth->id, 'months' => 12,
            ])
        );

        $this->assertSame('growth', app(PlanService::class)->planFor($this->vendor->fresh())->code);
        $this->assertSame(
            1,
            VendorSubscription::where('user_id', $this->vendor->id)->where('status', 'active')->count(),
            'the previous subscription is closed, not left running alongside'
        );
    }

    public function test_an_unknown_limit_key_is_refused_when_creating_a_plan(): void
    {
        // A typo would silently stop being enforced.
        $admin = $this->userWithRole('admin');

        $this->assertApiFailure(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/addPlan', [
                'code' => 'typo_plan', 'name' => 'Typo',
                'limits' => ['max_prodcuts' => 10],
            ]),
            422
        );

        $this->assertDatabaseMissing('plans', ['code' => 'typo_plan']);
    }

    public function test_the_admin_usage_report_explains_why_a_vendor_is_blocked(): void
    {
        $admin = $this->userWithRole('admin');
        $this->setLimit('max_products', 1);
        $this->addProduct();

        $response = $this->assertApiSuccess(
            $this->actingAs($admin, 'api')
                ->postJson('/admin/v2/vendorUsageReport', ['user_id' => $this->vendor->id])
        );

        $this->assertSame('free', $response->json('data.plan.code'));
        $this->assertTrue($response->json('data.usage.max_products.exceeded'));
    }

    public function test_plan_administration_is_closed_to_vendors(): void
    {
        foreach (['listPlans', 'addPlan', 'assignPlan', 'listSubscriptions', 'vendorUsageReport'] as $endpoint) {
            $this->actingAs($this->vendor, 'api')
                ->postJson("/admin/v2/{$endpoint}")
                ->assertStatus(403);
        }
    }
}
