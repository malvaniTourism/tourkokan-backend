<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government registration details for the "Verified business" tier (M3).
 *
 * Verification is optional and separate from listing approval: submission_status decides
 * whether a place is live, verification_status decides whether it carries the badge. A
 * business with no registration stays 'unverified' — a valid, permanent state ("Basic"
 * tier), not a queue.
 *
 * reg_number is encrypted at rest (Site casts it 'encrypted'), so the column must be TEXT
 * — ciphertext does not fit the 191-char string default and truncation destroys it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->enum('reg_type', ['udyam', 'gstin', 'shop_act'])->nullable()->after('rejection_reason');
            $table->text('reg_number')->nullable()->after('reg_type');
            $table->string('reg_doc')->nullable()->after('reg_number');
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])
                  ->default('unverified')
                  ->after('reg_doc');
            $table->timestamp('verified_at')->nullable()->after('verification_status');

            // the admin verification queue filters on this
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['reg_type', 'reg_number', 'reg_doc', 'verification_status', 'verified_at']);
        });
    }
};
