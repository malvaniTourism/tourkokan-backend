<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use Tests\ApiTestCase;

/**
 * The four admin screens, through their endpoints.
 *
 * What is worth pinning down here is less the arithmetic than the guardrails:
 * that the settings form cannot write a value which would break generation,
 * that a config change takes effect immediately rather than in an hour when
 * the cache expires, and that none of it is reachable without the admin role.
 */
class AiAdminScreensTest extends ApiTestCase
{
    private User $admin;
    private User $traveller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin     = $this->userWithRole('admin');
        $this->traveller = $this->userWithRole('user');
    }

    private function ai(string $endpoint, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'api')
            ->postJson("/admin/v2/ai/{$endpoint}", $body);
    }

    private function log(array $attributes = []): AiLog
    {
        return AiLog::create(array_merge([
            'user_id'  => $this->traveller->id,
            'type'     => 'plan',
            'status'   => 'success',
            'cost_inr' => 0.16,
            'valid_sites_used' => 8,
            'days_generated'   => 2,
            'candidates_sent'  => 40,
        ], $attributes));
    }

    // ── 1. Overview ──────────────────────────────────────────────────────────

    public function test_the_overview_reports_spend_against_the_ceiling(): void
    {
        AiConfig::set('daily_spend_ceiling', 10);
        $this->log(['cost_inr' => 2.50]);
        $this->log(['cost_inr' => 1.00]);

        $spend = $this->assertApiSuccess($this->ai('overview'))->json('data.spend');

        $this->assertSame(3.5, $spend['today']);
        $this->assertSame(10, $spend['ceiling']);
        $this->assertSame(6.5, $spend['remaining']);
        $this->assertSame(35, $spend['percent']);
        $this->assertTrue($spend['can_spend']);
    }

    public function test_the_overview_reports_the_cache_hit_rate(): void
    {
        $this->log(['status' => 'success']);
        $this->log(['status' => 'cached', 'cost_inr' => 0]);
        $this->log(['status' => 'cached', 'cost_inr' => 0]);
        $this->log(['status' => 'failed', 'failure_reason' => 'generation_failed']);

        $outcomes = $this->assertApiSuccess($this->ai('overview'))->json('data.outcomes');

        $this->assertSame(4, $outcomes['total']);
        $this->assertSame(50, $outcomes['cache_hit_rate']);
        $this->assertSame(25, $outcomes['failure_rate']);
    }

    public function test_the_overview_breaks_failures_out_by_reason(): void
    {
        $this->log(['status' => 'failed', 'failure_reason' => 'spend_ceiling']);
        $this->log(['status' => 'failed', 'failure_reason' => 'spend_ceiling']);
        $this->log(['status' => 'failed', 'failure_reason' => 'model_error']);

        $failures = collect(
            $this->assertApiSuccess($this->ai('overview'))->json('data.failures')
        )->pluck('count', 'reason');

        $this->assertSame(2, $failures['spend_ceiling']);
        $this->assertSame(1, $failures['model_error']);
    }

    public function test_the_overview_says_where_travellers_want_to_go(): void
    {
        $malvan = Site::create([
            'name' => 'Malvan', 'user_id' => $this->admin->id, 'status' => true,
            'submission_status' => 'approved', 'latitude' => 16.06, 'longitude' => 73.47,
        ]);

        $this->log(['input_filters' => ['town_site_ids' => [$malvan->id]]]);
        $this->log(['input_filters' => ['town_site_ids' => [$malvan->id]]]);

        $areas = $this->assertApiSuccess($this->ai('overview'))->json('data.areas');

        $this->assertSame('Malvan', $areas[0]['name']);
        $this->assertSame(2, $areas[0]['trips']);
    }

    // ── 2. Generations ───────────────────────────────────────────────────────

    public function test_the_log_list_filters_to_failures(): void
    {
        $this->log(['status' => 'success']);
        $failed = $this->log(['status' => 'failed', 'failure_reason' => 'day_too_thin']);

        $rows = $this->assertApiSuccess($this->ai('logs', ['status' => 'failed']))
            ->json('data.data');

        $this->assertCount(1, $rows);
        $this->assertSame($failed->id, $rows[0]['id']);
        $this->assertSame('day_too_thin', $rows[0]['failure_reason']);
    }

    public function test_the_log_list_leaves_the_heavy_columns_out(): void
    {
        $this->log(['ai_response' => ['days' => [['theme' => 'a lot of json']]]]);

        $row = $this->assertApiSuccess($this->ai('logs'))->json('data.data.0');

        // Sixty candidate ids and a full response per row would dominate the
        // table; they belong in the drawer.
        $this->assertArrayNotHasKey('ai_response', $row);
        $this->assertArrayNotHasKey('error_trace', $row);
    }

    public function test_the_detail_answers_a_complaint(): void
    {
        $log = $this->log([
            'input_filters'    => ['category_codes' => ['beach'], 'days' => 2],
            'dropped_site_ids' => [999],
            'ai_response'      => ['trip' => ['title' => 'Two days']],
            'prompt_hash'      => str_repeat('a', 64),
        ]);

        $detail = $this->assertApiSuccess($this->ai('getLog', ['id' => $log->id]))
            ->json('data');

        // The five things that make a complaint answerable.
        $this->assertSame(['beach'], $detail['filters']['category_codes']);
        $this->assertSame(40, $detail['candidates']['count']);
        $this->assertSame('Two days', $detail['raw_response']['trip']['title']);
        $this->assertSame([999], $detail['dropped']['site_ids']);
        $this->assertArrayHasKey('itinerary', $detail);
    }

    public function test_a_generation_can_be_flagged_and_marked_reviewed(): void
    {
        $log = $this->log();

        $this->assertApiSuccess($this->ai('reviewLog', [
            'id'          => $log->id,
            'flagged'     => true,
            'flag_reason' => 'Sent a traveller to a closed hotel',
        ]));

        $log->refresh();
        $this->assertTrue((bool) $log->is_flagged);
        $this->assertSame('Sent a traveller to a closed hotel', $log->flag_reason);

        $this->assertApiSuccess($this->ai('reviewLog', [
            'id'       => $log->id,
            'reviewed' => true,
        ]));

        $log->refresh();
        $this->assertTrue((bool) $log->admin_reviewed);
        $this->assertSame($this->admin->id, $log->reviewed_by);
    }

    // ── 3. Settings ──────────────────────────────────────────────────────────

    public function test_a_setting_change_takes_effect_at_once(): void
    {
        // AiConfig reads through a one-hour cache, so a write that bypassed it
        // would appear to do nothing for an hour — on Octane, with no deploy
        // to clear it.
        AiConfig::get('max_days');

        $this->assertApiSuccess($this->ai('updateConfig', [
            'key'   => 'max_days',
            'value' => 4,
        ]));

        $this->assertSame('4', AiConfig::get('max_days'));
    }

    public function test_a_setting_cannot_be_given_a_value_that_breaks_generation(): void
    {
        AiConfig::set('candidate_limit', 60);

        $this->assertApiFailure($this->ai('updateConfig', [
            'key'   => 'candidate_limit',
            'value' => 100000,
        ]), 422);

        $this->assertSame('60', AiConfig::get('candidate_limit'));
    }

    public function test_the_driver_cannot_be_set_to_something_that_does_not_exist(): void
    {
        $this->assertApiFailure($this->ai('updateConfig', [
            'key'   => 'ai_driver',
            'value' => 'ollama',
        ]), 422);
    }

    public function test_a_key_outside_the_form_cannot_be_written(): void
    {
        AiConfig::set('something_internal', 'keep');

        $this->assertApiFailure($this->ai('updateConfig', [
            'key'   => 'something_internal',
            'value' => 'changed',
        ]), 422);

        $this->assertSame('keep', AiConfig::get('something_internal'));
    }

    public function test_the_settings_screen_says_which_keys_it_can_edit(): void
    {
        AiConfig::set('something_internal', 'x');

        $rows = collect($this->assertApiSuccess($this->ai('config'))->json('data.config'))
            ->keyBy('key');

        $this->assertTrue($rows['max_days']['editable']);
        $this->assertFalse($rows['something_internal']['editable']);
    }

    // ── 4. Quota and credits ─────────────────────────────────────────────────

    public function test_a_users_credits_show_where_each_row_came_from(): void
    {
        $credits = app(CreditLedger::class);
        $credits->grant($this->traveller, 3, 'signup');
        $credits->spend($this->traveller, 77);

        $data = $this->assertApiSuccess(
            $this->ai('userCredits', ['user_id' => $this->traveller->id])
        )->json('data');

        $this->assertSame(2, $data['balance']);
        $this->assertSame(
            ['spend:trip:77', 'grant:signup'],
            collect($data['ledger'])->pluck('source')->all()
        );
    }

    public function test_an_admin_grant_is_written_to_the_ledger_with_its_reason(): void
    {
        $this->assertApiSuccess($this->ai('grantCredits', [
            'user_id' => $this->traveller->id,
            'credits' => 5,
            'reason'  => 'support ticket 412',
        ]));

        $data = $this->assertApiSuccess(
            $this->ai('userCredits', ['user_id' => $this->traveller->id])
        )->json('data');

        $this->assertSame(5, $data['balance']);
        $this->assertSame('grant:admin:support ticket 412', $data['ledger'][0]['source']);
    }

    public function test_a_user_can_be_found_by_their_exact_email(): void
    {
        $data = $this->assertApiSuccess(
            $this->ai('userCredits', ['search' => $this->traveller->email])
        )->json('data');

        $this->assertSame($this->traveller->id, $data['user']['id']);
    }

    public function test_a_partial_email_finds_nobody_and_says_so(): void
    {
        // Encrypted columns are reachable only through an exact blind index. A
        // LIKE over ciphertext would match nothing while looking like a search
        // that worked, which is worse than refusing.
        $partial = substr($this->traveller->email, 0, 5);

        $this->assertApiFailure($this->ai('userCredits', ['search' => $partial]), 404);
    }

    // ── The gate ─────────────────────────────────────────────────────────────

    public function test_none_of_it_is_reachable_without_the_admin_role(): void
    {
        foreach (['overview', 'logs', 'config', 'userCredits'] as $endpoint) {
            $this->ai($endpoint, [], $this->traveller)->assertStatus(403);
        }
    }

    public function test_none_of_it_is_reachable_without_authentication(): void
    {
        $this->postJson('/admin/v2/ai/overview')->assertStatus(401);
        $this->postJson('/admin/v2/ai/updateConfig', ['key' => 'max_days', 'value' => 1])
            ->assertStatus(401);
    }
}
