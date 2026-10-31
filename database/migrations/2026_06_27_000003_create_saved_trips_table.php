<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip, from the moment a traveller starts ticking places to the finished plan.
 *
 * A `draft` row is the cart: `favourites` rows point at it through `trip_id`, so
 * a cart is just favourites that share a trip. It becomes `generated` once an
 * itinerary has been written against it.
 */
class CreateSavedTripsTable extends Migration
{
    public function up()
    {
        Schema::create('saved_trips', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned();

            $table->string('title')->nullable();
            $table->enum('status', ['draft', 'generated'])->default('draft');

            $table->string('share_token', 64)->nullable()->unique();
            $table->boolean('is_public')->default(false);

            // Where the traveller is based for this trip; a site, so distances
            // and bus legs resolve against the same rows as everything else.
            $table->integer('base_site_id')->unsigned()->nullable();
            $table->integer('days')->default(0);

            // Snapshots. `itinerary` is null while the trip is still a cart.
            $table->json('filters')->nullable();
            $table->json('itinerary')->nullable();
            // Places pasted from a map link: they are not `sites`, so they live
            // here rather than polluting the public catalogue.
            $table->json('custom_stops')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('base_site_id')->references('id')->on('sites')->onDelete('set null');

            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('saved_trips');
    }
}
