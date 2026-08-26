<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw impression and click events behind the advertiser-facing numbers.
 *
 * `banners.impressions` / `banners.clicks` already existed as denormalised counters and stay
 * the fast read for a banner row. They cannot answer the two questions an advertiser
 * actually asks — "how did my campaign do across its run?" and "prove it" — because a
 * counter has no dates and no audit trail. This table is that record; the counters remain
 * the display value, exactly as `products.views_count` sits alongside `product_view_events`.
 *
 * `dedupe_key` is what makes the numbers defensible. An impression key is
 * banner|placement|session|date, so a carousel looping its slides all afternoon reports one
 * impression per person per placement per day rather than one per render — and a buggy app
 * release cannot inflate an advertiser's figures. Clicks carry a random key, so every tap is
 * kept.
 *
 * See docs/banner-tracking-backend-ask.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('banner_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->enum('event_type', ['impression', 'click']);

            // The slot the creative was rendered in — one creative may run in several.
            // Stored as the placement code the app already knows (HOME_HERO), not a join.
            $table->string('placement_code', 60)->nullable();

            $table->string('session_hash', 64)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('ip_hash', 64)->nullable();

            // Unique per counted event. Impressions collapse per session/day; clicks never do.
            $table->string('dedupe_key', 64)->unique();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('banner_id')->references('id')->on('banners')->cascadeOnDelete();

            // Reporting reads are always "this banner, this window".
            $table->index(['banner_id', 'event_type', 'created_at'], 'banner_events_reporting_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_events');
    }
};
