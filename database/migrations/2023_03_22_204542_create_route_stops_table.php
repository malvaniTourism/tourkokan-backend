<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRouteStopsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('serial_no')->unsigned();
            $table->integer('route_id')->unsigned();
            $table->integer('site_id')->unsigned()->comment('Bus stop or Bus depo id');
            $table->time('arr_time');
            $table->time('dept_time');
            $table->time('total_time')->nullable();
            $table->time('delayed_time')->nullable();
            $table->double('distance')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('route_stops');
    }
}
