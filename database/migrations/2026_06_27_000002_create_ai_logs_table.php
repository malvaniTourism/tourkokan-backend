<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per generation attempt, successful or not.
 *
 * This is the table the admin panel's Generations screen reads, and it is what
 * makes "a user says their trip was wrong" answerable: it keeps the candidates
 * offered, what the model returned, and which ids the assembler threw away.
 * Hallucination becomes a metric here rather than a support ticket.
 */
class CreateAiLogsTable extends Migration
{
    public function up()
    {
        Schema::create('ai_logs', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned()->nullable();
            $table->integer('trip_id')->unsigned()->nullable();

            $table->enum('type', ['plan', 'cart'])->default('plan');
            $table->enum('status', ['success', 'failed', 'cached'])->default('success');

            // ── What went in ────────────────────────────────────────────────
            $table->json('input_filters')->nullable();
            $table->integer('candidates_sent')->default(0);
            $table->string('prompt_hash', 64)->nullable();

            // ── The call itself ─────────────────────────────────────────────
            $table->string('driver', 20)->nullable();
            $table->string('model')->nullable();
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            // Rupees. Four decimals because one generation costs about 0.16.
            $table->decimal('cost_inr', 10, 4)->default(0);
            $table->integer('latency_ms')->default(0);

            // ── What came back, and what survived ───────────────────────────
            $table->json('ai_response')->nullable();
            $table->json('dropped_site_ids')->nullable();
            $table->integer('valid_sites_used')->default(0);
            $table->integer('days_generated')->default(0);

            // ── When it did not work ────────────────────────────────────────
            $table->string('failure_reason', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->longText('error_trace')->nullable();

            // ── Admin moderation ────────────────────────────────────────────
            $table->boolean('is_flagged')->default(false);
            $table->text('flag_reason')->nullable();
            $table->boolean('admin_reviewed')->default(false);
            $table->integer('reviewed_by')->unsigned()->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            // users.id is INT UNSIGNED in this schema, never bigint.
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('is_flagged');
            $table->index('prompt_hash');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_logs');
    }
}
