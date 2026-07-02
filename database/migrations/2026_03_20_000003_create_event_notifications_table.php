<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('event_notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->unsigned();
            $table->enum('type', ['new_event', 'event_reminder', 'event_update', 'event_cancelled', 'event_approved', 'event_rejected']);

            // Target Audience
            $table->enum('target_audience', ['all', 'followers', 'interested', 'going', 'nearby', 'category']);
            $table->enum('target_taluka', ['Devgad', 'Kudal', 'Malvan', 'Sawantwadi', 'Vengurla', 'Dodamarg', 'Kankavli', 'Vaibhavvadi'])->nullable();
            $table->string('target_category', 50)->nullable();

            // Notification Content
            $table->string('title');
            $table->text('body');
            $table->string('image_url')->nullable();
            $table->string('action_url')->nullable();

            // Scheduling
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'cancelled'])->default('pending');

            // Stats
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('opened_count')->default(0);
            $table->integer('clicked_count')->default(0);

            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->index(['event_id', 'type']);
            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('event_notifications');
    }
}
