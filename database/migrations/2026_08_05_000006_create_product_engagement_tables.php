<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement capture for the public catalog.
 *
 * Recording starts on day one of the free period even though billing is Phase 7 — launching
 * free without a meter leaves nothing to price on in twelve months' time.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 *
 * Two tables rather than one because they are billed differently and kept for different
 * lengths of time:
 *
 *   product_view_events  high volume, pruned at 90 days, rolled up nightly into
 *                        product_daily_stats (Phase 6). Shown to vendors as value proof,
 *                        never charged for — a vendor does not feel an impression.
 *
 *   product_leads        low volume, kept indefinitely. This is what vendors actually pay
 *                        for: a phone call, a WhatsApp, a request for directions.
 *
 * R6 — `lead_type` is an extensible enum. When booking ships, `booking_request` joins it and
 * the metering pipeline needs no schema change: a booking is a lead that converted.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §3.
 *
 * IP addresses are stored hashed. Raw addresses are personal data and nothing here needs to
 * reverse them — deduplication only needs equality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_view_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('referrer', 120)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('product_id')->references('id')->on('products')
                  ->onDelete('cascade')->onUpdate('cascade');

            $table->index(['product_id', 'created_at']);
            // supports both the nightly rollup and the 90-day prune
            $table->index('created_at');
        });

        Schema::create('product_leads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('lead_type', ['call', 'whatsapp', 'directions', 'enquiry'])
                  ->default('enquiry');
            $table->text('message')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')
                  ->onDelete('cascade')->onUpdate('cascade');

            $table->index(['product_id', 'lead_type']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_leads');
        Schema::dropIfExists('product_view_events');
    }
};
