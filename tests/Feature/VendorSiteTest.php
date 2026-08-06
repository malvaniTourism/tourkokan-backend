<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * A vendor's outlets are grouped solely by sites.user_id — there is no vendor entity.
 * `is_primary` marks the head location; `parent_id` stays the geographic tree.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §2.3.
 */
class VendorSiteTest extends ApiTestCase
{
    private User $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->userWithRole('vendor');
    }

    private function site(array $attributes = []): Site
    {
        return Site::create(array_merge([
            'name'              => 'Outlet ' . uniqid(),
            'description'       => 'A Kokan business listing used for testing purposes.',
            'user_id'           => $this->vendor->id,
            'status'            => true,
            'submission_status' => 'approved',
            'latitude'          => 16.0,
            'longitude'         => 73.4,
        ], $attributes));
    }

    // ── Ownership ────────────────────────────────────────────────────────────────

    public function test_a_vendor_owns_many_sites_through_user_id_alone(): void
    {
        $this->site();
        $this->site();
        $this->site(['user_id' => User::factory()->create()->id]);

        $this->assertCount(2, $this->vendor->sites, 'only the vendor\'s own sites');
        $this->assertCount(2, Site::ownedBy($this->vendor->id)->get());
    }

    public function test_platform_curated_sites_have_no_owner(): void
    {
        $this->site(['user_id' => null]);

        $this->assertSame(0, Site::ownedBy($this->vendor->id)->count());
    }

    public function test_approved_scope_excludes_pending_and_unpublished_sites(): void
    {
        $this->site();
        $this->site(['submission_status' => 'pending', 'status' => false]);
        $this->site(['submission_status' => 'approved', 'status' => false]);

        $this->assertSame(1, Site::ownedBy($this->vendor->id)->approved()->count());
    }

    // ── Primary location ─────────────────────────────────────────────────────────

    public function test_setting_a_primary_site_unsets_any_previous_one(): void
    {
        $first  = $this->site(['is_primary' => true]);
        $second = $this->site();

        $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/setPrimarySite', ['id' => $second->id])
        );

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(
            1,
            Site::ownedBy($this->vendor->id)->where('is_primary', true)->count(),
            'a vendor may never have two primary locations'
        );
    }

    public function test_a_vendor_cannot_make_someone_elses_site_primary(): void
    {
        $foreign = $this->site(['user_id' => User::factory()->create()->id]);

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/setPrimarySite', ['id' => $foreign->id]),
            404
        );

        $this->assertFalse($foreign->fresh()->is_primary);
    }

    public function test_an_unapproved_site_cannot_be_made_primary(): void
    {
        $pending = $this->site(['submission_status' => 'pending', 'status' => false]);

        $this->assertApiFailure(
            $this->actingAs($this->vendor, 'api')
                ->postJson('/api/v2/setPrimarySite', ['id' => $pending->id]),
            404
        );
    }

    // ── Branches keep their geographic parent ────────────────────────────────────

    public function test_branches_keep_their_geographic_parent_not_the_head_office(): void
    {
        $village = $this->site(['user_id' => null, 'name' => 'Malvan']);
        $head    = $this->site(['is_primary' => true, 'parent_id' => $village->id]);
        $branch  = $this->site(['parent_id' => $village->id]);

        // Both outlets hang off the village, never off each other — otherwise the branch
        // would vanish from the village listing and from nearby-search.
        $this->assertSame($village->id, $head->fresh()->parent_id);
        $this->assertSame($village->id, $branch->fresh()->parent_id);
        $this->assertCount(2, Site::where('parent_id', $village->id)->get());
    }

    // ── mySites ──────────────────────────────────────────────────────────────────

    public function test_my_sites_returns_approved_and_pending_outlets_primary_first(): void
    {
        // Pending businesses are included so a vendor can start listing against them
        // straight away — see design doc §2.6. Rejected ones are not.
        $this->site(['name' => 'Branch']);
        $this->site(['name' => 'Head', 'is_primary' => true]);
        $this->site(['name' => 'Pending', 'submission_status' => 'pending', 'status' => false]);
        $this->site(['name' => 'Rejected', 'submission_status' => 'rejected', 'status' => false]);

        $response = $this->assertApiSuccess(
            $this->actingAs($this->vendor, 'api')->postJson('/api/v2/mySites')
        );

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertCount(3, $names, 'rejected businesses are excluded');
        $this->assertNotContains('Rejected', $names);
        $this->assertSame('Head', $names->first(), 'primary location sorts first');
    }

    public function test_vendor_endpoints_are_closed_to_users_without_the_vendor_role(): void
    {
        $plainUser = User::factory()->create();

        $this->actingAs($plainUser, 'api')
            ->postJson('/api/v2/mySites')
            ->assertStatus(403);
    }

    public function test_vendor_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v2/mySites')->assertStatus(401);
    }
}
