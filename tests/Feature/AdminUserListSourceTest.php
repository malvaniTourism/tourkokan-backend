<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\ApiTestCase;

/**
 * The admin users list surfaces where each account signed up, so staff can see the
 * Android-vs-web split per user without leaving the list. See {@see AuthController::listUsers}.
 */
class AdminUserListSourceTest extends ApiTestCase
{
    public function test_the_admin_user_list_includes_registered_from(): void
    {
        $admin = $this->userWithRole('admin');
        User::factory()->create(['registered_from' => 'android']);
        User::factory()->create(['registered_from' => 'web']);

        $response = $this->assertApiSuccess(
            $this->actingAs($admin, 'api')
                ->postJson('/admin/v2/listUsers', ['apitype' => 'list'])
        );

        $rows = collect($response->json('data.data'));

        $this->assertTrue($rows->every(fn($r) => array_key_exists('registered_from', $r)),
            'Every user row must carry registered_from.');
        $this->assertNotEmpty(
            $rows->pluck('registered_from')->filter()->intersect(['android', 'web']),
            'The list should report android/web sources.'
        );
    }
}
