<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor plans and subscriptions.
 *
 * Listing is free for the first year. This exists now anyway, because the enforcement point
 * has to be in place *before* vendors accumulate listings — switching limits on afterwards
 * means retroactively putting existing accounts over quota, which is a conversation rather
 * than a deploy.
 *
 * Going paid should be a data change, not a code change: insert new `plans` rows, move
 * subscriptions onto them. Nothing in the enforcement path knows the price of anything.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('mr_name')->nullable();
            $table->text('description')->nullable();

            $table->decimal('price', 10, 2)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->enum('billing_period', ['free', 'monthly', 'quarterly', 'yearly'])->default('free');

            /**
             * Quotas, as {limit_key: int|null} — null means unlimited.
             *
             * Kept as JSON rather than columns so a new quota is a seeder change instead of
             * a migration. PlanService::LIMITS is the authoritative list of keys.
             */
            $table->json('limits')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('vendor_subscriptions', function (Blueprint $table) {
            $table->increments('id');
            // users.id is `int unsigned` (legacy increments()), so a foreign key here must
            // be unsignedInteger — unsignedBigInteger fails to constrain against it.
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('plan_id');

            $table->timestamp('starts_at')->nullable();
            // null = never expires. The launch free plan sets +12 months so the first
            // renewal decision has a date attached rather than drifting indefinitely.
            $table->timestamp('ends_at')->nullable();

            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->boolean('auto_renew')->default(false);
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')
                  ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('plan_id')->references('id')->on('plans')
                  ->onDelete('restrict')->onUpdate('cascade');

            // the active-subscription lookup runs on every quota check
            $table->index(['user_id', 'status']);
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_subscriptions');
        Schema::dropIfExists('plans');
    }
};
