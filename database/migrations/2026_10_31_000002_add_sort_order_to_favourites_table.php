<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order a traveller arranged their cart in.
 *
 * It is not decoration: `ItineraryPrompt` sends the picked places in this
 * order, so dragging a place to the top genuinely changes what the model is
 * shown first. Null on every ordinary favourite, which keeps today's behaviour.
 */
class AddSortOrderToFavouritesTable extends Migration
{
    public function up()
    {
        Schema::table('favourites', function (Blueprint $table) {
            $table->integer('sort_order')->nullable()->after('trip_id');
        });
    }

    public function down()
    {
        Schema::table('favourites', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
}
