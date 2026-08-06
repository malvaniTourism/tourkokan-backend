<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent per-day rollup of product engagement.
 *
 * `product_view_events` is high volume and pruned at 90 days; this table is the durable
 * record and the source for vendor analytics and, later, usage-based billing. Keeping the
 * aggregate separate is what lets the raw log be discarded without losing history.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_daily_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('product_id');
            $table->date('date');

            $table->unsignedInteger('views')->default(0);
            // distinct session_hash — the honest figure to show a vendor, since one person
            // reopening a listing five times is not five people
            $table->unsignedInteger('unique_views')->default(0);
            $table->unsignedInteger('leads')->default(0);

            // per-type lead breakdown; this is what the pricing model will be built on
            $table->unsignedInteger('leads_call')->default(0);
            $table->unsignedInteger('leads_whatsapp')->default(0);
            $table->unsignedInteger('leads_directions')->default(0);
            $table->unsignedInteger('leads_enquiry')->default(0);

            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')
                  ->onDelete('cascade')->onUpdate('cascade');

            // the rollup upserts on this pair, so it must be unique
            $table->unique(['product_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_daily_stats');
    }
};
