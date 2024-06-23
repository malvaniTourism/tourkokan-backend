<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->integer('bonus_id')->unsigned();
            $table->decimal('amount', 10, 2); // Amount with precision and scale
            $table->string('description')->nullable();
            $table->integer('referrer_id')->unsigned()->nullable();
            $table->integer('referee_id')->unsigned()->nullable();
            $table->softDeletes();
            $table->timestamps();            

            $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade');
            $table->foreign('bonus_id')->references('id')->on('bonus_types')->onUpdate('cascade');
            $table->foreign('referrer_id')->references('id')->on('users')->onUpdate('cascade');
            $table->foreign('referee_id')->references('id')->on('users')->onUpdate('cascade');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallets');
    }
}
