<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventInteractionsTable extends Migration
{
    public function up()
    {
        Schema::create('event_interactions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->enum('interaction_type', ['view', 'click', 'share', 'like', 'going', 'interested']);

            // Analytics metadata
            $table->string('device_type', 50)->nullable();
            $table->string('platform', 50)->nullable();
            $table->string('referrer')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Prevent duplicate interactions (one like/going/interested per user per event)
            $table->unique(['user_id', 'event_id', 'interaction_type'], 'unique_event_interaction');
            $table->index(['event_id', 'interaction_type']);
            $table->index(['user_id', 'interaction_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('event_interactions');
    }
}
