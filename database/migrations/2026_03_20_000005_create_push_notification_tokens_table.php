<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePushNotificationTokensTable extends Migration
{
    public function up()
    {
        Schema::create('push_notification_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->text('token');
            $table->enum('platform', ['android', 'ios', 'web']);
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'is_active']);
            $table->index(['platform', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('push_notification_tokens');
    }
}
