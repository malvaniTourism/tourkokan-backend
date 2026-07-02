<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->enum('submission_status', ['pending', 'approved', 'rejected'])
                  ->default('approved')
                  ->after('status');
            $table->text('rejection_reason')->nullable()->after('submission_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['submission_status', 'rejection_reason']);
        });
    }
};
