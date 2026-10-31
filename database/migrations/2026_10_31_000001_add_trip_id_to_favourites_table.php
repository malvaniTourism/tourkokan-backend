<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a favourite belong to a trip, which is what makes `favourites` the cart.
 *
 * Null is the existing behaviour exactly — a plain favourite, unattached to any
 * trip — so nothing about the current screen changes. A cart is simply the rows
 * that share one `trip_id`.
 */
class AddTripIdToFavouritesTable extends Migration
{
    public function up()
    {
        Schema::table('favourites', function (Blueprint $table) {
            $table->integer('trip_id')->unsigned()->nullable()->after('user_id');

            $table->foreign('trip_id')->references('id')->on('saved_trips')->onDelete('cascade');
            $table->index(['trip_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::table('favourites', function (Blueprint $table) {
            $table->dropForeign(['trip_id']);
            $table->dropIndex(['trip_id', 'user_id']);
            $table->dropColumn('trip_id');
        });
    }
}
