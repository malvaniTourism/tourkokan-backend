<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event_type', 50)->index();
            $table->string('entity_type', 50)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('entity_name', 255)->nullable();
            $table->string('route', 150)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->boolean('success')->default(true);
            $table->unsignedSmallInteger('response_time_ms')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'event_type']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['event_type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
};
