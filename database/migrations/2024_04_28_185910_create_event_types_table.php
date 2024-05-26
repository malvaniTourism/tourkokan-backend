<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEventTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->unsigned()->nullable();
            $table->string('name');
            $table->string('mr_name');
            $table->string('code');
            $table->string('icon');
            $table->boolean('status')->default(0);
            $table->boolean('is_hot_type')->default(0);
            $table->string('description')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('event_types')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('event_types');
    }
}
