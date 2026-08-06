<?php

namespace Tests;

use App\Models\AppVersion;
use App\Models\Roles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Base class for tests that hit the JSON API.
 *
 * Two things about this codebase make a shared base worthwhile:
 *
 *  1. `PreMiddleware` resolves an app_version from the `app_versions` table, and
 *     `BaseController::sendResponse` refuses to return a payload without one. A fresh test
 *     database has no rows, so every API test must seed a version or silently receive
 *     "Unauthorised Access".
 *
 *  2. That refusal is returned with HTTP **200**, as are validation failures elsewhere.
 *     `assertOk()` is therefore not evidence of success here — use `assertApiSuccess()` /
 *     `assertApiFailure()`, which check the `success` flag in the envelope.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AppVersion::create([
            'platform'       => 'android',
            'version_number' => '1.0.0',
            'release_date'   => now()->toDateString(),
        ]);
    }

    /**
     * A user holding the given role code, creating the role if the test DB lacks it.
     */
    protected function userWithRole(string $code): User
    {
        $role = Roles::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code)]
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Assert the response carries a successful API envelope, not merely HTTP 200.
     */
    protected function assertApiSuccess(TestResponse $response): TestResponse
    {
        $response->assertOk();

        $this->assertTrue(
            $response->json('success') === true,
            'Expected a successful API envelope, got: ' . $response->getContent()
        );

        return $response;
    }

    /**
     * Assert the response reports failure, whatever status code it chose to use.
     */
    protected function assertApiFailure(TestResponse $response, ?int $status = null): TestResponse
    {
        if ($status !== null) {
            $response->assertStatus($status);
        }

        $this->assertFalse(
            $response->json('success') === true,
            'Expected a failed API envelope, got: ' . $response->getContent()
        );

        return $response;
    }
}
