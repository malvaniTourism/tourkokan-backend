<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The model's answers, kept so the same question is never paid for twice.
 *
 * Sindhudurg is a small query space: "three days, beaches and forts, based in
 * Malvan" is a question hundreds of travellers ask in slightly different
 * words, and there is no reason to buy that answer more than once.
 *
 * What is stored is the model's DECISION — the ordering, the day themes, the
 * notes — and not the finished itinerary. On a hit the places are fetched
 * fresh and the plan is reassembled through the same validator, so a cached
 * plan carries today's phone numbers and today's bus times, and a place that
 * has since closed is dropped exactly as a hallucinated one would be. If too
 * much of the answer no longer validates, the entry is thrown away and the
 * question is asked again. Correctness therefore does not depend on anyone
 * remembering to invalidate.
 *
 * Not the framework cache: `site_ids` has to be queryable for the admin
 * panel's placement work, the hit counts are a metric, and an entry should
 * survive a `cache:clear`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_itinerary_cache', function (Blueprint $table) {
            $table->increments('id');

            // The normalised question: sorted category codes, day count,
            // rounded coordinates. Rounding is what lets two travellers
            // asking the same thing from different pins share one answer.
            $table->string('filter_hash', 64)->unique();

            // The model's response as it came back, before validation.
            $table->json('response');
            // Every place the answer named, for the placement screens.
            $table->json('site_ids');

            $table->integer('days')->unsigned()->default(0);
            $table->integer('hits')->unsigned()->default(0);
            $table->timestamp('last_hit_at')->nullable();

            // What the first generation cost. Kept so the panel can show what
            // caching has actually saved rather than estimating it.
            $table->decimal('cost_inr', 10, 4)->default(0);

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_itinerary_cache');
    }
};
