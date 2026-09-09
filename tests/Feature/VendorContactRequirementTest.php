<?php

namespace Tests\Feature;

use App\Models\Roles;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Vendors are reachable by buyers only if they carry a WhatsApp mobile number and an email,
 * and — once set — the mobile cannot be blanked out through a profile edit.
 *
 * The label was made WhatsApp-specific because that is the channel buyers actually contact a
 * vendor on. See {@see \App\Http\Middleware\VendorMiddleware}.
 */
class VendorContactRequirementTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Roles::firstOrCreate(['code' => 'vendor'], ['name' => 'Vendor']);
    }

    public function test_requesting_vendor_without_a_mobile_names_the_whatsapp_number(): void
    {
        $user = User::factory()->create(['mobile' => null]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v2/requestRole', ['role_code' => 'vendor']);

        $response->assertStatus(403);
        $this->assertStringContainsString('WhatsApp mobile number', $response->json('message'));
        $this->assertContains('mobile', $response->json('data.missing_profile_fields'));
    }

    public function test_both_mobile_and_email_are_required_not_either(): void
    {
        // Has email (factory default) but no mobile — a single contact is not enough.
        $user = User::factory()->create(['mobile' => null]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v2/requestRole', ['role_code' => 'vendor'])
            ->assertStatus(403);

        // Has mobile but no email — still not enough.
        $noEmail = User::factory()->create(['email' => null]);

        $this->actingAs($noEmail, 'api')
            ->postJson('/api/v2/requestRole', ['role_code' => 'vendor'])
            ->assertStatus(403);
    }

    public function test_a_complete_profile_can_request_vendor_access(): void
    {
        $user = User::factory()->create(); // factory now sets both mobile and email

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->postJson('/api/v2/requestRole', ['role_code' => 'vendor'])
        );
    }

    public function test_profile_edit_rejects_a_null_mobile_with_the_whatsapp_message(): void
    {
        $user = User::factory()->create();

        foreach (['', null] as $blank) {
            $response = $this->actingAs($user, 'api')
                ->postJson('/api/v2/updateProfile', ['mobile' => $blank]);

            $this->assertApiFailure($response);
            $this->assertSame(
                'WhatsApp mobile number is required.',
                $response->json('message.mobile.0'),
                'Blanking the mobile must be rejected with the WhatsApp-specific message.'
            );
        }

        // The stored number is untouched by the rejected edits.
        $this->assertNotEmpty($user->fresh()->getRawOriginal('mobile'));
    }

    public function test_profile_edit_without_the_mobile_field_is_unaffected(): void
    {
        $user = User::factory()->create();

        // Editing only the name must not demand the mobile be resent.
        $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->postJson('/api/v2/updateProfile', ['name' => 'Renamed User'])
        );
    }

    public function test_profile_edit_accepts_a_valid_mobile(): void
    {
        $user = User::factory()->create();

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')
                ->postJson('/api/v2/updateProfile', ['mobile' => '9123456780'])
        );
    }
}
