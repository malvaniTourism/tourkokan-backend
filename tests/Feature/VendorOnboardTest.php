<?php

namespace Tests\Feature;

use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Roles;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRoleRequest;
use Tests\ApiTestCase;

/**
 * The combined first-time web wizard (M5): one submission carries the business,
 * optional verification details, an optional first product, and raises the
 * vendor role request — admitted only for web callers with zero existing sites.
 *
 * See docs/vendor-onboarding-plan.md M5 and VendorOnboardController.
 */
class VendorOnboardTest extends ApiTestCase
{
    private function taxonomy(): array
    {
        $category = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल रूम', 'code' => 'hotel_rooms',
            'icon' => 'x.png', 'status' => true,
        ]);
        $productCategory = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);
        AllowedProductCategory::create([
            'category_id' => $category->id, 'product_category_id' => $productCategory->id,
        ]);
        Roles::firstOrCreate(['code' => 'vendor'], ['name' => 'Vendor']);

        return [$category, $productCategory];
    }

    private function payload(Category $category, array $extra = []): array
    {
        return $extra + [
            'name'        => 'Sagar Resort Tarkarli',
            'categories'  => [$category->id],
            'description' => 'A sea-facing resort in Tarkarli with AC and non-AC rooms.',
            'latitude'    => 16.0512,
            'longitude'   => 73.4680,
        ];
    }

    public function test_the_combined_submission_creates_site_product_and_role_request(): void
    {
        [$category, $productCategory] = $this->taxonomy();
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-App-Source', 'web')
            ->postJson('/api/v2/vendorOnboard', $this->payload($category, [
                'reg_type'   => 'udyam',
                'reg_number' => 'UDYAM-MH-18-0012345',
                'consent'    => 1,
                'product'    => [
                    'name'                => 'Deluxe AC Room',
                    'product_category_id' => $productCategory->id,
                    'base_price'          => 2500,
                ],
            ]));

        $this->assertApiSuccess($response);

        $site = Site::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $site->submission_status);
        $this->assertSame('pending', $site->verification_status);
        $this->assertSame('UDYAM-MH-18-0012345', $site->reg_number);

        $product = Product::where('site_id', $site->id)->firstOrFail();
        $this->assertSame('draft', $product->status);
        $this->assertNotNull($product->variants()->where('is_default', true)->first());

        $this->assertDatabaseHas('user_role_requests', [
            'user_id' => $user->id,
            'status'  => 'pending',
        ]);

        // the response never leaks the encrypted registration details
        $response->assertJsonMissingPath('data.site.reg_number');
    }

    public function test_only_web_callers_are_admitted(): void
    {
        [$category] = $this->taxonomy();
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/v2/vendorOnboard', $this->payload($category))
            ->assertStatus(403);
    }

    public function test_a_user_with_a_site_is_not_first_time(): void
    {
        [$category] = $this->taxonomy();
        $user = User::factory()->create();

        Site::create([
            'name' => 'Existing Biz', 'mr_name' => 'x', 'description' => 'd', 'mr_description' => 'd',
            'user_id' => $user->id, 'status' => false, 'submission_status' => 'pending',
        ]);

        $this->actingAs($user, 'api')
            ->withHeader('X-App-Source', 'web')
            ->postJson('/api/v2/vendorOnboard', $this->payload($category))
            ->assertStatus(422);
    }

    public function test_missing_contact_details_return_the_machine_readable_gate(): void
    {
        [$category] = $this->taxonomy();
        $user = User::factory()->create(['mobile' => null]);

        $this->actingAs($user, 'api')
            ->withHeader('X-App-Source', 'web')
            ->postJson('/api/v2/vendorOnboard', $this->payload($category))
            ->assertStatus(403)
            ->assertJsonPath('data.missing_profile_fields.0', 'mobile');
    }

    public function test_an_admin_decides_the_verification(): void
    {
        [$category, $productCategory] = $this->taxonomy();
        $user  = User::factory()->create();
        $admin = $this->userWithRole('admin');

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->withHeader('X-App-Source', 'web')
                ->postJson('/api/v2/vendorOnboard', $this->payload($category, [
                    'reg_type' => 'gstin', 'reg_number' => '27ABCDE1234F1Z5', 'consent' => 1,
                ]))
        );

        $site = Site::where('user_id', $user->id)->firstOrFail();

        // the queue lists it, with registration details visible to the reviewer
        $this->actingAs($admin, 'api')
            ->postJson('/admin/v2/pendingVerifications')
            ->assertOk()
            ->assertJsonPath('data.data.0.reg_number', '27ABCDE1234F1Z5');

        // rejecting needs a note
        $this->actingAs($admin, 'api')
            ->postJson('/admin/v2/verifySiteRegistration', ['id' => $site->id, 'decision' => 'rejected'])
            ->assertStatus(422);

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')
                ->postJson('/admin/v2/verifySiteRegistration', ['id' => $site->id, 'decision' => 'verified'])
        );

        $site->refresh();
        $this->assertSame('verified', $site->verification_status);
        $this->assertNotNull($site->verified_at);

        // an already-decided verification cannot be decided again
        $this->actingAs($admin, 'api')
            ->postJson('/admin/v2/verifySiteRegistration', ['id' => $site->id, 'decision' => 'verified'])
            ->assertStatus(422);
    }

    public function test_a_vendor_role_request_is_not_duplicated(): void
    {
        [$category] = $this->taxonomy();
        $user = User::factory()->create();

        UserRoleRequest::create([
            'user_id' => $user->id,
            'role_id' => Roles::where('code', 'vendor')->value('id'),
            'status'  => 'pending',
            'reason'  => 'earlier request from the app',
        ]);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->withHeader('X-App-Source', 'web')
                ->postJson('/api/v2/vendorOnboard', $this->payload($category))
        );

        $this->assertSame(1, UserRoleRequest::where('user_id', $user->id)->count());
    }
}
