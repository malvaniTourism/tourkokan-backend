<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\ApiTestCase;

/**
 * A guest account (name only, no email/mobile) can page through the first two pages of the
 * route lists; asking for page 3+ returns a sign-up nudge instead. A registered user — anyone
 * with an email or mobile on file — is never limited.
 */
class GuestRoutePaginationTest extends ApiTestCase
{
    private function guest(): User
    {
        // Unverified — the state a guest token carries; regular login can't reach it.
        return User::factory()->create(['isVerified' => false]);
    }

    private function registered(): User
    {
        return User::factory()->create(['isVerified' => true]);
    }

    private function hit(User $user, string $endpoint, int $page)
    {
        return $this->actingAs($user, 'api')->postJson("/api/v2/{$endpoint}", ['page' => $page]);
    }

    public function test_the_guest_helper_keys_off_verification(): void
    {
        $this->assertTrue($this->guest()->isGuest(), 'An unverified account is a guest.');
        $this->assertFalse($this->registered()->isGuest(), 'A verified account is not a guest.');
    }

    /** @dataProvider endpoints */
    public function test_a_guest_may_read_the_first_two_pages(string $endpoint): void
    {
        $guest = $this->guest();

        foreach ([1, 2] as $page) {
            $this->assertApiSuccess($this->hit($guest, $endpoint, $page));
        }
    }

    /** @dataProvider endpoints */
    public function test_a_guest_is_walled_off_at_page_three(string $endpoint): void
    {
        $response = $this->hit($this->guest(), $endpoint, 3);

        $this->assertApiFailure($response);
        $this->assertTrue($response->json('data.requires_signup'));
        $this->assertSame(2, $response->json('data.guest_page_limit'));
    }

    /** @dataProvider endpoints */
    public function test_a_registered_user_is_never_walled(string $endpoint): void
    {
        $this->assertApiSuccess($this->hit($this->registered(), $endpoint, 3));
        $this->assertApiSuccess($this->hit($this->registered(), $endpoint, 50));
    }

    public static function endpoints(): array
    {
        return [
            'route search' => ['routes'],
            'route list'   => ['listroutes'],
        ];
    }
}
