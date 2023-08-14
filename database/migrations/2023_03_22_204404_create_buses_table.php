<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buses', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('bus_type_id')->unsigned()->nullable();
            $table->string('name')->nullable();
            $table->string('no_plate')->unique()->nullable();
            $table->json('description')->nullable(); //model/capacity/date_of_join/year_of_life
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('bus_type_id')->references('id')->on('bus_types')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buses');
    }
}
