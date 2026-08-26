<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\BannerEvent;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Advertising performance capture.
 *
 * The numbers these endpoints produce are shown to paying advertisers, so the tests that
 * matter most are the ones about *not* over-counting: a looping carousel must not inflate an
 * impression, and an expired campaign must not keep accruing.
 *
 * See docs/banner-tracking-backend-ask.md §1.
 */
class BannerTrackingTest extends ApiTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = $this->userWithRole('user');
    }

    private function makeBanner(array $overrides = []): Banner
    {
        return Banner::create(array_merge([
            'name'         => 'Resort promo ' . uniqid(),
            'image'        => 'local/banners/' . uniqid() . '.png',
            'redirect_url' => 'https://example.test/offer',
            'start_date'   => now()->subDay()->toDateString(),
            'end_date'     => now()->addMonth()->toDateString(),
            'duration'     => 30,
            'status'       => true,
            'is_active'    => true,
            'impressions'  => 0,
            'clicks'       => 0,
        ], $overrides));
    }

    private function fire(string $endpoint, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->viewer, 'api')
            ->postJson("/api/v2/{$endpoint}", $payload);
    }

    // ── Impressions ──────────────────────────────────────────────────────────────

    public function test_an_impression_is_recorded_and_counted(): void
    {
        $banner = $this->makeBanner();

        $response = $this->assertApiSuccess(
            $this->fire('recordBannerImpression', [
                'id' => $banner->id, 'placement' => 'HOME_HERO', 'platform' => 'app',
            ])
        );

        $this->assertTrue($response->json('data.counted'));
        $this->assertSame(1, $banner->fresh()->impressions);
        $this->assertDatabaseHas('banner_events', [
            'banner_id'      => $banner->id,
            'event_type'     => 'impression',
            'placement_code' => 'HOME_HERO',
        ]);
    }

    public function test_a_looping_carousel_does_not_inflate_the_impression_count(): void
    {
        $banner = $this->makeBanner();

        // Same slide coming back around five times inside one session.
        foreach (range(1, 5) as $ignored) {
            $this->assertApiSuccess(
                $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_MIDDLE'])
            );
        }

        $this->assertSame(1, $banner->fresh()->impressions, 'Impressions collapse per session, placement and day.');
        $this->assertSame(1, BannerEvent::where('banner_id', $banner->id)->count());
    }

    public function test_a_repeat_impression_reports_counted_false_rather_than_failing(): void
    {
        $banner = $this->makeBanner();

        $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_HERO']);

        $response = $this->assertApiSuccess(
            $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_HERO'])
        );

        $this->assertFalse($response->json('data.counted'));
    }

    public function test_the_same_creative_in_two_placements_counts_separately(): void
    {
        $banner = $this->makeBanner();

        $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_HERO']);
        $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'CITY_MIDDLE']);

        $this->assertSame(2, $banner->fresh()->impressions, 'One creative running in two slots earns two impressions.');
    }

    public function test_two_different_people_each_count(): void
    {
        $banner = $this->makeBanner();
        $other  = $this->userWithRole('user');

        $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_HERO']);
        $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_HERO'], $other);

        $this->assertSame(2, $banner->fresh()->impressions);
    }

    // ── Clicks ───────────────────────────────────────────────────────────────────

    public function test_a_click_is_recorded_and_counted(): void
    {
        $banner = $this->makeBanner();

        $this->assertApiSuccess(
            $this->fire('recordBannerClick', ['id' => $banner->id, 'placement' => 'HOME_HERO', 'platform' => 'app'])
        );

        $this->assertSame(1, $banner->fresh()->clicks);
        $this->assertSame(0, $banner->fresh()->impressions, 'A click must not also increment impressions.');
    }

    public function test_repeat_clicks_all_count(): void
    {
        $banner = $this->makeBanner();

        foreach (range(1, 3) as $ignored) {
            $this->fire('recordBannerClick', ['id' => $banner->id, 'placement' => 'HOME_HERO']);
        }

        $this->assertSame(3, $banner->fresh()->clicks, 'A repeat tap is genuine repeat interest.');
    }

    // ── Only live campaigns accrue ───────────────────────────────────────────────

    public function test_an_expired_campaign_records_nothing(): void
    {
        $banner = $this->makeBanner([
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date'   => now()->subDay()->toDateString(),
        ]);

        $this->assertApiFailure(
            $this->fire('recordBannerImpression', ['id' => $banner->id, 'placement' => 'HOME_HERO'])
        );

        $this->assertSame(0, $banner->fresh()->impressions);
    }

    public function test_an_unpublished_banner_records_nothing(): void
    {
        $banner = $this->makeBanner(['is_active' => false]);

        $this->assertApiFailure(
            $this->fire('recordBannerClick', ['id' => $banner->id, 'placement' => 'HOME_HERO'])
        );

        $this->assertSame(0, $banner->fresh()->clicks);
    }

    // ── Contract ─────────────────────────────────────────────────────────────────

    public function test_placement_and_platform_are_optional(): void
    {
        $banner = $this->makeBanner();

        $this->assertApiSuccess($this->fire('recordBannerImpression', ['id' => $banner->id]));

        $this->assertSame(1, $banner->fresh()->impressions);
    }

    public function test_an_unknown_banner_is_rejected(): void
    {
        $this->assertApiFailure($this->fire('recordBannerImpression', ['id' => 999999]));
    }

    public function test_both_endpoints_require_authentication(): void
    {
        $banner = $this->makeBanner();

        foreach (['recordBannerImpression', 'recordBannerClick'] as $endpoint) {
            $this->postJson("/api/v2/{$endpoint}", ['id' => $banner->id])->assertStatus(401);
        }
    }

    public function test_identity_hashes_are_never_exposed(): void
    {
        $banner = $this->makeBanner();

        $response = $this->fire('recordBannerImpression', ['id' => $banner->id]);

        $body = $response->getContent();
        foreach (['ip_hash', 'session_hash', 'dedupe_key'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
