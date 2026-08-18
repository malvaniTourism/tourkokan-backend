<?php

namespace Tests\Feature;

use App\Models\AdminMessage;
use App\Models\AllowedProductCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLead;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * A vendor being told things happened.
 *
 * The lead case is the one that matters commercially: the platform's promise is that it
 * delivers enquiries, and one nobody hears about is a lost booking — and later, a disputed
 * invoice once leads are billable.
 */
class VendorNotificationTest extends ApiTestCase
{
    private User $vendor;
    private User $admin;
    private User $tourist;
    private Product $product;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor  = $this->userWithRole('vendor');
        $this->admin   = $this->userWithRole('admin');
        $this->tourist = $this->userWithRole('user');

        $cat = Category::create(['name' => 'Hotel Rooms', 'mr_name' => 'हॉटेल', 'code' => 'hotel_rooms',
                                 'icon' => '', 'status' => true]);
        $pc  = ProductCategory::create(['name' => 'Room Night', 'code' => 'room_night', 'slug' => 'room-night']);
        AllowedProductCategory::create(['category_id' => $cat->id, 'product_category_id' => $pc->id]);

        $this->site = Site::create([
            'name' => 'Sagar Resort', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => true, 'submission_status' => 'approved',
            'latitude' => 16.05, 'longitude' => 73.46,
        ]);
        $this->site->categories()->attach($cat->id);

        $this->product = Product::create([
            'site_id' => $this->site->id, 'product_category_id' => $pc->id,
            'name' => 'Sea View Room', 'slug' => 'sea-view-room',
            'base_price' => 2400, 'status' => 'approved',
        ]);
        ProductVariant::create(['product_id' => $this->product->id, 'name' => 'Standard',
                                'price' => 2400, 'is_default' => true, 'status' => true]);
    }

    private function inbox(): \Illuminate\Database\Eloquent\Collection
    {
        return AdminMessage::where('user_id', $this->vendor->id)->get();
    }

    public function test_a_lead_notifies_the_business_owner(): void
    {
        $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
            'id' => $this->product->id, 'lead_type' => 'whatsapp', 'message' => 'Free next weekend?',
        ]);

        $msg = $this->inbox()->firstWhere('type', 'lead');

        $this->assertNotNull($msg, 'the owner must hear about an enquiry');
        $this->assertStringContainsString('Sea View Room', $msg->message);
        $this->assertStringContainsString('Free next weekend?', $msg->message);
        $this->assertSame($this->product->id, $msg->meta_data['product_id']);
        $this->assertNull($msg->admin_id, 'system-generated, no admin behind it');
        $this->assertFalse($msg->is_read);
    }

    public function test_the_lead_is_still_recorded_even_if_nobody_can_be_notified(): void
    {
        // An ownerless site is the degenerate case; the enquiry must survive regardless.
        $this->site->update(['user_id' => null]);

        $this->assertApiSuccess(
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
                'id' => $this->product->id, 'lead_type' => 'call',
            ])
        );

        $this->assertSame(1, ProductLead::count());
    }

    public function test_approval_and_rejection_notify_the_vendor(): void
    {
        // refresh() before each transition: the HTTP call changes the row underneath this
        // instance, and update() on a stale model issues no query at all.
        $this->product->refresh()->update(['status' => 'pending']);
        $this->actingAs($this->admin, 'api')->postJson('/admin/v2/approveProduct', ['id' => $this->product->id]);

        $this->assertNotNull($this->inbox()->firstWhere('type', 'product_approved'));

        $this->product->refresh()->update(['status' => 'pending']);
        $this->actingAs($this->admin, 'api')->postJson('/admin/v2/rejectProduct', [
            'id' => $this->product->id, 'rejection_reason' => 'Photos are blurry.',
        ]);

        $rejected = $this->inbox()->firstWhere('type', 'product_rejected');
        $this->assertNotNull($rejected);
        $this->assertStringContainsString('Photos are blurry.', $rejected->message,
            'the reason is the actionable part');
    }

    public function test_site_approval_notifies_the_vendor(): void
    {
        $pending = Site::create([
            'name' => 'Second Outlet', 'description' => 'A Kokan business used for testing purposes.',
            'user_id' => $this->vendor->id, 'status' => false, 'submission_status' => 'pending',
            'latitude' => 16.06, 'longitude' => 73.47,
        ]);

        $this->actingAs($this->admin, 'api')->postJson('/admin/v2/approveSite', ['id' => $pending->id]);

        $this->assertNotNull($this->inbox()->firstWhere('type', 'site_approved'));
    }

    public function test_notifications_reach_the_existing_inbox_endpoint(): void
    {
        // The point of reusing admin_messages: no new app screen is needed.
        $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
            'id' => $this->product->id, 'lead_type' => 'call',
        ]);

        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myMessages')
        );

        $rows = $r->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('lead', $rows[0]['type']);
        $this->assertSame('New enquiry', $rows[0]['subject']);
        $this->assertFalse($rows[0]['is_read']);
    }

    // ── Lead read state ──────────────────────────────────────────────────────────

    public function test_a_vendor_can_mark_a_lead_handled(): void
    {
        $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
            'id' => $this->product->id, 'lead_type' => 'call',
        ]);
        $lead = ProductLead::first();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/markLeadRead', ['id' => $lead->id])
        );

        $this->assertTrue($lead->fresh()->is_read);
    }

    public function test_a_vendor_cannot_mark_someone_elses_lead(): void
    {
        $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
            'id' => $this->product->id, 'lead_type' => 'call',
        ]);
        $lead     = ProductLead::first();
        $intruder = $this->userWithRole('vendor');

        $this->assertApiFailure(
            $this->actingAs($intruder, 'api')->postJson('/api/v2/markLeadRead', ['id' => $lead->id]),
            404
        );
        $this->assertFalse($lead->fresh()->is_read);
    }

    public function test_my_leads_reports_an_unread_count(): void
    {
        foreach (['call', 'whatsapp'] as $type) {
            $this->actingAs($this->tourist, 'api')->postJson('/api/v2/recordProductLead', [
                'id' => $this->product->id, 'lead_type' => $type,
            ]);
        }

        $r = $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myLeads');
        $this->assertSame(2, $r->json('data.unread_count'));

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/markLeadRead', ['all' => true]);

        $r = $this->actingAs($this->vendor, 'api')->postJson('/api/v2/myLeads');
        $this->assertSame(0, $r->json('data.unread_count'));
    }

    // ── Bulk status ──────────────────────────────────────────────────────────────

    public function test_bulk_pause_and_resume(): void
    {
        $ids = collect(range(1, 3))->map(function ($i) {
            $p = Product::create([
                'site_id' => $this->site->id, 'product_category_id' => $this->product->product_category_id,
                'name' => "Room {$i}", 'slug' => "room-{$i}", 'base_price' => 1000, 'status' => 'approved',
            ]);
            ProductVariant::create(['product_id' => $p->id, 'name' => 'Standard',
                                    'price' => 1000, 'is_default' => true, 'status' => true]);
            return $p->id;
        })->all();

        $r = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/bulkProductStatus', [
                'ids' => $ids, 'status' => 'paused',
            ])
        );

        $this->assertSame(3, $r->json('data.updated'));
        $this->assertSame(3, Product::whereIn('id', $ids)->where('status', 'paused')->count());

        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/bulkProductStatus', [
            'ids' => $ids, 'status' => 'approved',
        ]);
        $this->assertSame(3, Product::whereIn('id', $ids)->where('status', 'approved')->count());
    }

    public function test_bulk_status_cannot_touch_another_vendors_products_or_revive_drafts(): void
    {
        $draft = Product::create([
            'site_id' => $this->site->id, 'product_category_id' => $this->product->product_category_id,
            'name' => 'Draft Room', 'slug' => 'draft-room', 'base_price' => 900, 'status' => 'draft',
        ]);

        $intruder = $this->userWithRole('vendor');
        $r = $this->actingAs($intruder, 'api')->postJson('/api/v2/bulkProductStatus', [
            'ids' => [$this->product->id, $draft->id], 'status' => 'paused',
        ]);

        $this->assertSame(0, $r->json('data.updated'), 'not their products');
        $this->assertSame('approved', $this->product->fresh()->status);

        // owner, but a draft is not resumable via bulk
        $this->actingAs($this->vendor, 'api')->postJson('/api/v2/bulkProductStatus', [
            'ids' => [$draft->id], 'status' => 'approved',
        ]);
        $this->assertSame('draft', $draft->fresh()->status);
    }
}
