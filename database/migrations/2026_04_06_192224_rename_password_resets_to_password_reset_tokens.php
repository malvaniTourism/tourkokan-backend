<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only rename if old table still exists and new one doesn't
        if (Schema::hasTable('password_resets') && !Schema::hasTable('password_reset_tokens')) {
            Schema::rename('password_resets', 'password_reset_tokens');

            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->primary('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('password_reset_tokens') && !Schema::hasTable('password_resets')) {
            Schema::rename('password_reset_tokens', 'password_resets');
        }
    }
};
