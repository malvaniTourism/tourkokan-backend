<?php

namespace Tests\Feature;

use App\Models\BonusTypes;
use App\Models\Roles;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Every signup records whether it came from the Android app or the web, so the split can be
 * reported without inferring it from request logs. The app is identified by the
 * X-App-Source: mobile header (or an okhttp user agent); everything else is web.
 */
class RegistrationSourceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Roles::firstOrCreate(['code' => 'tourist'], ['name' => 'Tourist']);
        BonusTypes::firstOrCreate(['code' => 'joining_bonus_coins'], ['amount' => 100]);
    }

    private function register(array $body, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/v2/auth/register', $body);
    }

    private function payload(string $email): array
    {
        return [
            'name'     => 'New Tourist',
            'email'    => $email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];
    }

    public function test_signup_with_the_app_header_is_recorded_as_android(): void
    {
        $this->assertApiSuccess(
            $this->register($this->payload('android@tourkokan.test'), ['X-App-Source' => 'mobile'])
        );

        $user = User::findByEmail('android@tourkokan.test');
        $this->assertSame('android', $user->registered_from);
    }

    public function test_signup_with_an_okhttp_agent_is_recorded_as_android(): void
    {
        $this->assertApiSuccess(
            $this->register($this->payload('okhttp@tourkokan.test'), ['User-Agent' => 'okhttp/4.12.0'])
        );

        $this->assertSame('android', User::findByEmail('okhttp@tourkokan.test')->registered_from);
    }

    public function test_signup_from_a_browser_is_recorded_as_web(): void
    {
        $this->assertApiSuccess(
            $this->register($this->payload('web@tourkokan.test'), ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0)'])
        );

        $this->assertSame('web', User::findByEmail('web@tourkokan.test')->registered_from);
    }

    public function test_signup_with_no_recognisable_client_defaults_to_web(): void
    {
        $this->assertApiSuccess(
            $this->register($this->payload('bare@tourkokan.test'))
        );

        $this->assertSame('web', User::findByEmail('bare@tourkokan.test')->registered_from);
    }
}
