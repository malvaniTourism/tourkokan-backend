<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSitesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->index();
            $table->string('mr_name')->index();
            $table->integer('parent_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned()->nullable();
            $table->string('bus_stop_type')->nullable();
            $table->string('tag_line')->nullable();
            $table->string('mr_tag_line')->nullable();
            $table->text('description');
            $table->text('mr_description');
            $table->string('domain_name')->nullable();
            $table->string('logo')->nullable();
            $table->string('icon')->nullable();
            $table->string('image')->nullable();
            $table->boolean('status')->default(false);
            $table->boolean('is_hot_place')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('pin_code')->nullable();
            $table->json('speciality')->nullable();
            $table->json('rules')->nullable();
            $table->json('social_media')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('sites')->onUpdate('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sites');
    }
}
