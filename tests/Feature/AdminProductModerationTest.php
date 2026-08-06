<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Admin review of vendor listings, mirroring the site-submission flow.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §7.
 */
class AdminProductModerationTest extends ApiTestCase
{
    private User $admin;
    private User $vendor;
    private Site $site;
    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = $this->userWithRole('admin');
        $this->vendor = $this->userWithRole('vendor');

        $this->site = Site::create([
            'name'              => 'Sagar Resort',
            'description'       => 'A Kokan business listing used for testing purposes.',
            'user_id'           => $this->vendor->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => 16.0,
            'longitude'         => 73.4,
        ]);

        $this->category = ProductCategory::create([
            'name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night',
            'booking_type' => 'date_range',
        ]);
    }

    private function product(string $status = 'pending', array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'site_id'             => $this->site->id,
            'product_category_id' => $this->category->id,
            'name'                => 'Deluxe Room ' . uniqid(),
            'slug'                => 'deluxe-' . uniqid(),
            'base_price'          => 2400,
            'status'              => $status,
        ], $overrides));

        ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Standard',
            'price' => 2400, 'is_default' => true, 'status' => true,
        ]);

        return $product;
    }

    // ── Queue ────────────────────────────────────────────────────────────────────

    public function test_the_review_queue_holds_only_pending_products_oldest_first(): void
    {
        $first = $this->product('pending');
        $this->travel(1)->minutes();
        $this->product('pending');
        $this->product('draft');
        $this->product('approved');

        $response = $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/pendingProducts')
        );

        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertCount(2, $ids, 'drafts and approved listings are not awaiting review');
        $this->assertSame($first->id, $ids->first(), 'longest-waiting first');
    }

    // ── Approval ─────────────────────────────────────────────────────────────────

    public function test_an_admin_can_approve_a_pending_product(): void
    {
        $product = $this->product('pending');

        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/approveProduct', ['id' => $product->id])
        );

        $this->assertSame('approved', $product->fresh()->status);
    }

    public function test_a_draft_cannot_be_approved(): void
    {
        $product = $this->product('draft');

        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/approveProduct', ['id' => $product->id]),
            422
        );

        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_a_product_on_an_unpublished_site_cannot_be_approved(): void
    {
        // Otherwise the listing goes live under a site tourists cannot reach.
        $product = $this->product('pending');
        $this->site->update(['status' => false, 'submission_status' => 'pending']);

        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/approveProduct', ['id' => $product->id]),
            422
        );

        $this->assertSame('pending', $product->fresh()->status);
    }

    // ── Rejection ────────────────────────────────────────────────────────────────

    public function test_rejection_requires_and_records_a_reason(): void
    {
        $product = $this->product('pending');

        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/rejectProduct', ['id' => $product->id]),
            422
        );

        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')->postJson('/admin/v2/rejectProduct', [
                'id' => $product->id, 'rejection_reason' => 'Images do not match the description.',
            ])
        );

        $product->refresh();

        $this->assertSame('rejected', $product->status);
        $this->assertSame('Images do not match the description.', $product->rejection_reason);
    }

    public function test_approving_clears_a_previous_rejection_reason(): void
    {
        $product = $this->product('rejected', ['rejection_reason' => 'Blurry photos']);
        $product->update(['status' => 'pending']);

        $this->actingAs($this->admin, 'api')->postJson('/admin/v2/approveProduct', ['id' => $product->id]);

        $this->assertNull($product->fresh()->rejection_reason);
    }

    // ── Featuring ────────────────────────────────────────────────────────────────

    public function test_only_an_approved_product_can_be_featured(): void
    {
        $pending = $this->product('pending');

        $this->assertApiFailure(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/featureProduct', ['id' => $pending->id, 'is_featured' => true]),
            422
        );

        $approved = $this->product('approved');

        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/featureProduct', ['id' => $approved->id, 'is_featured' => true])
        );

        $this->assertTrue($approved->fresh()->is_featured);
    }

    public function test_a_product_can_always_be_un_featured(): void
    {
        $product = $this->product('approved', ['is_featured' => true]);
        $product->update(['status' => 'paused']);

        $this->assertApiSuccess(
            $this->actingAs($this->admin, 'api')
                ->postJson('/admin/v2/featureProduct', ['id' => $product->id, 'is_featured' => false])
        );

        $this->assertFalse($product->fresh()->is_featured);
    }

    // ── Access ───────────────────────────────────────────────────────────────────

    public function test_a_vendor_cannot_reach_the_moderation_endpoints(): void
    {
        $product = $this->product('pending');

        foreach (['pendingProducts', 'approveProduct', 'rejectProduct', 'featureProduct', 'listAllProducts'] as $endpoint) {
            $this->actingAs($this->vendor, 'api')
                ->postJson("/admin/v2/{$endpoint}", ['id' => $product->id])
                ->assertStatus(403);
        }

        $this->assertSame('pending', $product->fresh()->status);
    }

    // ── Live scope ───────────────────────────────────────────────────────────────

    public function test_the_live_scope_respects_status_and_availability_window(): void
    {
        $this->product('approved');
        $this->product('pending');
        $this->product('paused');
        $this->product('approved', ['available_to' => now()->subDay()->toDateString()]);
        $this->product('approved', ['available_from' => now()->addWeek()->toDateString()]);

        $this->assertSame(
            1,
            Product::live()->count(),
            'only an approved product inside its availability window is live'
        );
    }
}
