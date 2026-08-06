<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMERCE-READY — see docs/VENDOR_PRODUCTS_DESIGN.md §3b (C1–C5).
 *
 * Selling through the platform (cart, orders, payments, payouts) is NOT built. Almost all
 * of it is a pure addition later: new tables keyed on product_variants.id, which is already
 * the unit of sale.
 *
 * These columns are the exception — the parts that are nearly free now and disproportionately
 * expensive once real orders exist:
 *
 *   C3  Tax. Order lines snapshot the tax rate at purchase time, and historical orders
 *       cannot be back-filled with a rate that was never recorded. Adding GST/HSN before
 *       order #1 costs one migration; adding it after is an accounting problem, not a
 *       schema problem.
 *
 *   C4  fulfilment_type. Every listing today is implicitly enquiry-only. Introducing this
 *       after launch means backfilling every existing product with a guess about what the
 *       vendor meant. Shipping it now, defaulting to `enquiry` and not vendor-writable, is
 *       the same trick that made `is_bookable` free (R3).
 *
 * min/max order quantity are additive either way, but they cost nothing here and save a
 * second migration on the variants table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // C3 — India means GST. HSN for goods, SAC for services.
            $table->string('hsn_code', 12)->nullable()->after('currency');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('hsn_code');
            // Indian retail convention: the displayed price is what the customer pays.
            $table->boolean('price_includes_tax')->default(true)->after('tax_rate');

            // C4 — how this listing is transacted. `enquiry` until commerce ships.
            $table->enum('fulfilment_type', ['enquiry', 'order', 'booking'])
                  ->default('enquiry')
                  ->after('is_bookable');

            $table->index(['fulfilment_type', 'status']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('min_order_qty')->nullable()->after('stock');
            $table->unsignedInteger('max_order_qty')->nullable()->after('min_order_qty');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['min_order_qty', 'max_order_qty']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['fulfilment_type', 'status']);
            $table->dropColumn(['hsn_code', 'tax_rate', 'price_includes_tax', 'fulfilment_type']);
        });
    }
};
