<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTourPackagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tour_packages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('project_id')->unsigned();
            $table->string('title')->nullable();
            $table->string('tag_line')->nullable();
            $table->text('description');
            $table->string('image_url');
            $table->json('duration')->nullable(); 
            $table->json('dates')->nullable(); 
            $table->json('price')->nullable(); 
            $table->json('rules')->nullable(); 
            $table->json('ambience')->nullable(); 
            $table->json('includes')->nullable(); 
            $table->json('itinerary')->nullable(); 
            $table->json('contact_details')->nullable(); 
            $table->json('social_media')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tour_packages');
    }
}
