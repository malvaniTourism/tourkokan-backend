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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\ApiTestCase;

/**
 * The complete journey a real vendor takes, exercised through the HTTP API only —
 * no model shortcuts — so this proves the whole chain actually connects:
 *
 *   ordinary user → request vendor role → admin approves
 *                 → submit business as a site → admin approves
 *                 → add product → upload image → submit for review
 *                 → admin approves → product is live
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §1 and §8.
 */
class VendorJourneyTest extends ApiTestCase
{
    public function test_a_new_user_can_become_a_vendor_and_get_a_product_live(): void
    {
        Storage::fake();

        // The platform's taxonomy, as seeded in production.
        $hotelRooms = Category::create([
            'name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल रूम', 'code' => 'hotel_rooms',
            'icon' => 'x.png', 'status' => true,
        ]);
        $roomNight = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
            'attribute_schema' => [
                'occupancy' => ['type' => 'int', 'label' => 'Max guests', 'required' => true, 'min' => 1, 'max' => 20],
                'ac'        => ['type' => 'bool', 'label' => 'Air conditioned'],
            ],
        ]);
        AllowedProductCategory::create([
            'category_id' => $hotelRooms->id, 'product_category_id' => $roomNight->id,
        ]);

        $admin = $this->userWithRole('admin');
        Roles::firstOrCreate(['code' => 'vendor'], ['name' => 'Vendor']);

        // ── 1. An ordinary registered user ───────────────────────────────────────
        $user = User::factory()->create();

        $this->actingAs($user, 'api')->postJson('/api/v2/addSite')->assertStatus(403);
        $this->actingAs($user, 'api')->postJson('/api/v2/myProducts')->assertStatus(403);

        // ── 2. Requests the vendor role ──────────────────────────────────────────
        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->postJson('/api/v2/requestRole', [
                'role_code' => 'vendor',
                'reason'    => 'I run a resort in Tarkarli and want to list my rooms.',
            ])
        );

        // ── 3. Admin approves the role request ───────────────────────────────────
        $request = UserRoleRequest::where('user_id', $user->id)->firstOrFail();

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')
                ->postJson('/admin/v2/approveRoleRequest', ['id' => $request->id])
        );

        $user->refresh()->load('roles');
        $this->assertTrue($user->hasRole('vendor'), 'the user is now a vendor');

        // ── 4. Submits their business as a site ──────────────────────────────────
        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->postJson('/api/v2/addSite', [
                'name'        => 'Sagar Resort Tarkarli',
                'categories'  => [$hotelRooms->id],
                'description' => 'A sea-facing resort in Tarkarli with AC and non-AC rooms.',
                'latitude'    => 16.0512,
                'longitude'   => 73.4680,
            ])
        );

        $site = Site::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $site->submission_status);

        // The vendor starts listing straight away rather than waiting out a second
        // approval round — see design doc §2.6.
        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->postJson('/api/v2/addProduct', [
                'site_id'             => $site->id,
                'product_category_id' => $roomNight->id,
                'name'                => 'Early Bird Room',
                'base_price'          => 1800,
                'attributes'          => ['occupancy' => 2],
            ])
        );
        $this->assertSame(0, Product::live()->count(), 'nothing is public yet');

        // ── 5. Admin approves the site ───────────────────────────────────────────
        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/approveSite', ['id' => $site->id])
        );

        $site->refresh();
        // sites.status is a tinyint with no boolean cast on the model, so it reads as 1.
        $this->assertTrue((bool) $site->status, 'the business is live');
        $this->assertTrue($site->is_primary, 'a vendor\'s first approved site becomes primary');

        // ── 6. The app asks what this outlet may sell, then how to render the form ─
        $allowed = $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->postJson('/api/v2/allowedProductCategories', ['site_id' => $site->id])
        );
        $this->assertSame(['room_night'], collect($allowed->json('data'))->pluck('code')->all());

        $schema = $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->postJson('/api/v2/categoryAttributeSchema', ['product_category_id' => $roomNight->id])
        );
        $this->assertSame('Max guests', $schema->json('data.attribute_schema.occupancy.label'));

        // ── 7. Adds a product, exactly as the RN app sends it (strings throughout) ─
        $created = $this->assertApiSuccess(
            $this->actingAs($user, 'api')->postJson('/api/v2/addProduct', [
                'site_id'             => $site->id,
                'product_category_id' => $roomNight->id,
                'name'                => 'Deluxe Sea View Room',
                'description'         => 'Sea-facing room with a private balcony.',
                'base_price'          => '2400',
                'unit'                => 'per_night',
                'attributes'          => json_encode(['occupancy' => '3', 'ac' => 'true']),
            ])
        );

        $product = Product::findOrFail($created->json('data.id'));
        $this->assertSame('draft', $product->status);
        $this->assertSame('2400.00', $product->price, 'price resolves through the auto-created variant');
        $this->assertSame(3, $product->getAttribute('attributes')['occupancy']);
        $this->assertTrue($product->getAttribute('attributes')['ac']);

        // ── 8. Uploads a photo ───────────────────────────────────────────────────
        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->postJson('/api/v2/uploadProductMedia', [
                'id' => $product->id, 'image' => UploadedFile::fake()->image('room.jpg'),
            ])
        );
        $this->assertSame(1, $product->fresh()->gallery()->where('is_cover', true)->count());

        // ── 9. Submits for review ────────────────────────────────────────────────
        $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->postJson('/api/v2/submitProductForReview', ['id' => $product->id])
        );
        $this->assertSame('pending', $product->fresh()->status);

        // ── 10. Admin approves it ────────────────────────────────────────────────
        $queue = $this->assertApiSuccess(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/pendingProducts')
        );
        $this->assertSame($product->id, $queue->json('data.data.0.id'), 'it appears in the review queue');

        $this->assertApiSuccess(
            $this->actingAs($admin, 'api')->postJson('/admin/v2/approveProduct', ['id' => $product->id])
        );

        // ── The product is live ──────────────────────────────────────────────────
        $this->assertSame('approved', $product->fresh()->status);
        $this->assertSame(1, Product::live()->count(), 'the listing is publicly visible');
    }

    /**
     * `users.isVerified` (mobile OTP) plays no part in the vendor path — the gate is the
     * `vendor` role plus admin approval of both the business and each listing.
     */
    public function test_mobile_verification_is_not_what_gates_vendor_access(): void
    {
        $unverified = $this->userWithRole('vendor');
        $unverified->update(['isVerified' => false]);

        $this->assertApiSuccess(
            $this->actingAs($unverified->fresh(), 'api')->postJson('/api/v2/mySites')
        );
    }
}
