<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

/**
 * The `/admin/v2/*` group was missing its `admin` middleware, leaving the entire admin API
 * open to any authenticated user — a vendor could approve their own site submission, grant
 * themselves a role, or delete another user's event.
 *
 * These tests exist so that cannot silently regress.
 */
class AdminAccessTest extends ApiTestCase
{
    /**
     * A representative slice of the group: reads, destructive writes, moderation and
     * privilege escalation.
     *
     * @return array<string, array{string}>
     */
    public static function adminEndpoints(): array
    {
        return [
            'listBanners'              => ['listBanners'],
            'listAppVersions'          => ['listAppVersions'],
            'pendingSites'             => ['pendingSites'],
            'listEvents'               => ['listEvents'],
            'analytics dashboard'      => ['analytics/dashboardStats'],
            'approveSite'              => ['approveSite'],
            'rejectSite'               => ['rejectSite'],
            'deleteEvent'              => ['deleteEvent'],
            'approveRoleRequest'       => ['approveRoleRequest'],
            'allUsers'                 => ['allUsers'],
            'sendMessage'              => ['sendMessage'],
            'deleteBanner'             => ['deleteBanner'],
            'listProductCategories'    => ['listProductCategories'],
            'addProductCategory'       => ['addProductCategory'],
            'setAllowedProductCategories' => ['setAllowedProductCategories'],
        ];
    }

    #[DataProvider('adminEndpoints')]
    public function test_a_vendor_cannot_reach_admin_endpoints(string $endpoint): void
    {
        $vendor = $this->userWithRole('vendor');

        $this->actingAs($vendor, 'api')
            ->postJson("/admin/v2/{$endpoint}")
            ->assertStatus(403);
    }

    #[DataProvider('adminEndpoints')]
    public function test_a_plain_user_cannot_reach_admin_endpoints(string $endpoint): void
    {
        $user = $this->userWithRole('user');

        $this->actingAs($user, 'api')
            ->postJson("/admin/v2/{$endpoint}")
            ->assertStatus(403);
    }

    #[DataProvider('adminEndpoints')]
    public function test_anonymous_requests_cannot_reach_admin_endpoints(string $endpoint): void
    {
        $this->postJson("/admin/v2/{$endpoint}")->assertStatus(401);
    }

    public function test_an_admin_is_allowed_through_the_guard(): void
    {
        $admin = $this->userWithRole('admin');

        // 403 is the guard; anything else means the request reached the controller.
        $this->actingAs($admin, 'api')
            ->postJson('/admin/v2/listProductCategories')
            ->assertStatus(200);
    }

    public function test_a_superadmin_is_allowed_through_the_guard(): void
    {
        $superadmin = $this->userWithRole('superadmin');

        $this->actingAs($superadmin, 'api')
            ->postJson('/admin/v2/listProductCategories')
            ->assertStatus(200);
    }

    public function test_a_user_who_loses_the_admin_role_loses_access(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'api')
            ->postJson('/admin/v2/listProductCategories')
            ->assertStatus(200);

        $admin->roles()->detach();

        $this->actingAs($admin->fresh(), 'api')
            ->postJson('/admin/v2/listProductCategories')
            ->assertStatus(403);
    }

    public function test_the_vendor_facing_api_is_unaffected_by_the_admin_guard(): void
    {
        $vendor = $this->userWithRole('vendor');

        $this->assertApiSuccess(
            $this->actingAs($vendor, 'api')->postJson('/api/v2/mySites')
        );
    }

    public function test_the_admin_route_management_group_still_requires_admin(): void
    {
        $vendor = $this->userWithRole('vendor');

        $this->actingAs($vendor, 'api')
            ->postJson('/admin/v2/addRoute')
            ->assertStatus(403);
    }
}
