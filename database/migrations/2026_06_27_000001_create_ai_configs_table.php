<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tunables for the trip planner, editable by an admin without a deploy.
 *
 * The backend runs Octane, so a config change that lives in a .env file means a
 * pull, a reload and a wait. Anything an admin might need to tighten during an
 * abuse spike or a cost spike belongs here instead.
 */
class CreateAiConfigsTable extends Migration
{
    public function up()
    {
        Schema::create('ai_configs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $now = now();

        $defaults = [
            // ── Model ────────────────────────────────────────────────────────
            ['key' => 'ai_driver',             'value' => 'fake',              'description' => 'AI client driver: fake | claude'],
            ['key' => 'ai_model',              'value' => 'claude-haiku-5-5',  'description' => 'Model id used for itinerary generation'],
            ['key' => 'ai_timeout',            'value' => '45',                'description' => 'Model request timeout (seconds)'],

            // ── Shape of a trip ──────────────────────────────────────────────
            ['key' => 'candidate_limit',       'value' => '60',                'description' => 'Max candidate places sent to the model'],
            ['key' => 'min_cart_places',       'value' => '3',                 'description' => 'Fewest places a cart needs before it can be planned'],
            ['key' => 'min_stops_per_day',     'value' => '2',                 'description' => 'Below this after validation, the generation fails'],
            ['key' => 'max_stops_per_day',     'value' => '5',                 'description' => 'Most stops the model may place in one day'],
            ['key' => 'max_days',              'value' => '7',                 'description' => 'Longest trip that can be requested'],

            // ── Free quota ───────────────────────────────────────────────────
            ['key' => 'free_plans_on_signup',  'value' => '3',                 'description' => 'Free generations granted on email verification'],
            ['key' => 'free_plans_referral',   'value' => '2',                 'description' => 'Free generations granted per successful referral'],

            // ── Limits and the circuit breaker ───────────────────────────────
            ['key' => 'rate_limit_per_hour',   'value' => '10',                'description' => 'Generations per user per hour'],
            ['key' => 'rate_limit_per_day',    'value' => '20',                'description' => 'Generations per user per day'],
            ['key' => 'daily_spend_ceiling',   'value' => '50.00',             'description' => 'Rupees per day; past this, serve cache only'],

            // ── Cache ────────────────────────────────────────────────────────
            ['key' => 'cache_enabled',         'value' => 'true',              'description' => 'Reuse an itinerary for an identical filter set'],
            ['key' => 'cache_ttl_hours',       'value' => '168',               'description' => 'How long a cached itinerary stays valid'],
        ];

        foreach ($defaults as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('ai_configs')->insert($defaults);
    }

    public function down()
    {
        Schema::dropIfExists('ai_configs');
    }
}
