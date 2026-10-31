<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row every time a place appears in a generated itinerary.
 *
 * This is the whole vendor sales conversation: "your homestay appeared in 40
 * itineraries and got 12 enquiries." Without it, month three arrives with
 * nothing to show a vendor, which is why it ships with the first itinerary
 * rather than later — the rows cannot be back-filled.
 *
 * It also feeds the placement audit. If paid vendors start crowding out the
 * rest, this table is where that becomes visible before travellers notice.
 */
class CreateTripPlacementsTable extends Migration
{
    public function up()
    {
        Schema::create('trip_placements', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('site_id')->unsigned();
            $table->integer('trip_id')->unsigned();
            // The traveller, for counting people reached rather than trips.
            // Never shown to a vendor — they see totals, never who.
            $table->integer('user_id')->unsigned()->nullable();

            $table->unsignedSmallInteger('day')->default(1);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
            $table->foreign('trip_id')->references('id')->on('saved_trips')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // The vendor-facing query: appearances for one business over a period.
            $table->index(['site_id', 'created_at']);
            // Regenerating a trip replaces its rows rather than adding to them.
            $table->index('trip_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('trip_placements');
    }
}
