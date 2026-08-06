<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor product catalog.
 *
 * A product belongs to a site; the owning vendor is derived through `site->user_id`.
 * There is no vendor_id or user_id here on purpose — two ownership columns can disagree,
 * and that disagreement is a permission bug waiting to happen.
 *
 * ── BOOKING-READY — see docs/VENDOR_PRODUCTS_DESIGN.md §3 (R1–R6) ────────────────────
 *
 * Booking/availability is NOT built. These columns exist so adding it later is a pure
 * addition rather than a rewrite:
 *
 *   R1  `base_price` is a DISPLAY value only ("from ₹1,200"). The authoritative price is
 *       product_variants.price, and a future product_availability.price_override sits
 *       above that. Never read a price straight off `products` — every product has at
 *       least one variant, auto-created when the vendor supplies only one price.
 *   R3  `is_bookable` defaults false; every launch listing is enquiry-only. Turning one
 *       bookable later is a boolean update, not a migration.
 *   R4  `unit` is a fixed enum because nightly/per-person maths depends on it. Free text
 *       would mean a data cleanup before the calendar could ship.
 *   R5  `attributes` holds STATIC facts only. Anything varying by date — price, stock,
 *       slots — belongs in the future product_availability table. Enforced in
 *       ProductAttributeValidator::RESERVED_KEYS.
 *
 * FK note: sites.id and product_categories.id are `int unsigned` (legacy increments()),
 * so referencing columns must be unsignedInteger — foreignId() emits bigint and fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->unsignedInteger('product_category_id');

            $table->string('name');
            $table->string('mr_name')->nullable();
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('mr_description')->nullable();

            // Validated against product_categories.attribute_schema on write. R5: static only.
            $table->json('attributes')->nullable();

            $table->decimal('base_price', 10, 2)->nullable();   // R1 — display only
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->enum('unit', [
                'per_night', 'per_person', 'per_plate', 'per_kg',
                'per_hour', 'per_piece', 'per_package',
            ])->nullable();                                      // R4

            $table->boolean('is_bookable')->default(false);      // R3

            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'paused'])
                  ->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_featured')->default(false);

            $table->date('available_from')->nullable();
            $table->date('available_to')->nullable();

            // Denormalised for fast display; the source of truth is product_daily_stats.
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('leads_count')->default(0);

            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('site_id')->references('id')->on('sites')
                  ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('product_category_id')->references('id')->on('product_categories')
                  ->onDelete('cascade')->onUpdate('cascade');

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
            $table->index(['product_category_id', 'status']);
            $table->index(['status', 'is_featured']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');

            $table->string('name');
            $table->string('sku')->nullable();

            // R1 — the authoritative price lives here, never on `products`.
            $table->decimal('price', 10, 2);
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->unsignedInteger('stock')->nullable();  // null = not stock-tracked

            $table->json('attributes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('status')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')
                  ->onDelete('cascade')->onUpdate('cascade');

            $table->index(['product_id', 'status']);
        });

        // Product images reuse the existing Gallery morph rather than a new table, so the
        // upload/delete plumbing and admin gallery tooling work unchanged. These two
        // columns are what product listings additionally need — and Site/Event galleries
        // get ordering and a cover image for free.
        Schema::table('galleries', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('status');
            $table->boolean('is_cover')->default(false)->after('sort_order');

            $table->index(['galleryable_type', 'galleryable_id', 'sort_order'], 'galleries_morph_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropIndex('galleries_morph_order_index');
            $table->dropColumn(['sort_order', 'is_cover']);
        });

        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
