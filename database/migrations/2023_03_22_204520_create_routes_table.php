<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoutesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('source_place_id')->unsigned()->nullable();
            $table->integer('destination_place_id')->unsigned()->nullable();
            $table->integer('bus_type_id')->unsigned()->nullable();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->double('distance')->nullable();
            $table->json('meta_data')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->time('total_time')->nullable();
            $table->time('delayed_time')->nullable();
            $table->string('working_days')->nullable();
            $table->timestamps();

            $table->foreign('source_place_id')->references('id')->on('sites')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('destination_place_id')->references('id')->on('sites')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('bus_type_id')->references('id')->on('bus_types')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('routes');
    }
}
