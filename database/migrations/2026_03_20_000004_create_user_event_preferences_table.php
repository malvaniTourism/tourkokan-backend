<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserEventPreferencesTable extends Migration
{
    public function up()
    {
        Schema::create('user_event_preferences', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->unique();

            // Notification Preferences
            $table->boolean('notify_new_events')->default(true);
            $table->boolean('notify_event_reminders')->default(true);
            $table->boolean('notify_event_updates')->default(true);

            // Interest-based preferences
            $table->json('preferred_talukas')->nullable();
            $table->json('preferred_event_types')->nullable();

            // Notification channels
            $table->boolean('email_notifications')->default(true);
            $table->boolean('push_notifications')->default(true);
            $table->boolean('sms_notifications')->default(false);

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_event_preferences');
    }
}
