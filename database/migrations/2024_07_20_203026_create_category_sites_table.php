<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategorySitesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('category_site', function (Blueprint $table) {
            $table->integer('category_id')->unsigned()->nullable();
            $table->integer('site_id')->unsigned()->nullable();
            
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade')->onUpdate('cascade');
            
            $table->primary(['category_id', 'site_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('category_sites');
    }
}
