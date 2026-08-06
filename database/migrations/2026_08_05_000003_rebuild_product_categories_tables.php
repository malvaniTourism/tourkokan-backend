<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the product taxonomy for the vendor catalog.
 *
 * Both tables were verified empty (0 rows), so they are recreated outright rather than
 * patched with a stack of ALTERs.
 *
 * Fixes two live bugs in the 2022 schema: `icon` and `meta_data` were NOT NULL with no
 * default, while the controller marked them optional — every insert without them failed.
 *
 * BOOKING-READY: `booking_type` is shipped now and read by nothing yet. It exists so the
 * taxonomy is already correct when the availability calendar lands, with no re-seed.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §3 (R2).
 *
 * FK note: categories.id is `int unsigned` (legacy increments()), so referencing columns
 * must be unsignedInteger — foreignId() would emit bigint and fail to constrain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('allowed_product_categories');
        Schema::dropIfExists('product_categories');

        Schema::create('product_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();

            $table->string('name');
            $table->string('mr_name')->nullable();          // app is i18n (i18next)
            $table->string('code')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();             // BUG FIX: was NOT NULL

            // Drives the app's dynamic Add-Product form and server-side validation. §6
            $table->json('attribute_schema')->nullable();

            // R2 — shipped now, read later by the booking calendar. §3
            $table->enum('booking_type', ['none', 'date_range', 'slot', 'quantity'])
                  ->default('none');

            $table->boolean('status')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('meta_data')->nullable();          // BUG FIX: was NOT NULL
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('product_categories')
                  ->onDelete('cascade')->onUpdate('cascade');
            $table->index(['status', 'sort_order']);
        });

        Schema::create('allowed_product_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id');          // site category
            $table->unsignedInteger('product_category_id');

            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('max_products')->nullable();  // per-site quota
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')
                  ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('product_category_id')->references('id')->on('product_categories')
                  ->onDelete('cascade')->onUpdate('cascade');

            $table->unique(['category_id', 'product_category_id'], 'apc_category_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_product_categories');
        Schema::dropIfExists('product_categories');

        Schema::create('product_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('icon');
            $table->json('meta_data');
            $table->timestamps();
        });

        Schema::create('allowed_product_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('product_category_id')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')
                  ->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('product_category_id')->references('id')->on('product_categories')
                  ->onDelete('cascade')->onUpdate('cascade');
        });
    }
};
