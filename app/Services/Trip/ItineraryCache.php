<?php

namespace App\Services\Trip;

use App\Models\AiConfig;
use App\Models\AiItineraryCache;
use Illuminate\Support\Collection;

/**
 * The result cache: the same question is never bought twice.
 *
 * This is the main lever on unit cost, and a bigger one than model choice.
 * It is also the honest near-term version of "train our own model later" —
 * generated itineraries are outputs, not labelled training data, and caching
 * delivers the same economics today with no ML work.
 *
 * What is cached is the model's decision. The places are refetched on every
 * hit and run through the assembler again, so a cached plan is as current as a
 * fresh one and a closed business falls out of it on its own.
 */
class ItineraryCache
{
    /**
     * The question, normalised to the point where two travellers asking the
     * same thing agree.
     *
     * Coordinates are rounded to two decimals — roughly a kilometre — because
     * a base town picked from a list gives everyone the same pin, but a
     * coordinate arriving from a map pin would otherwise make every request
     * unique and the cache useless.
     *
     * @param  array<int,string>  $categoryCodes
     */
    public function key(
        array $categoryCodes,
        int $days,
        float $lat,
        float $lng,
        float $radiusKm,
        string $stay = 'suggest',
        ?array $arrival = null,
        ?array $group = null,
        ?string $travellerNote = null
    ): string {
        $codes = array_values(array_unique($categoryCodes));
        sort($codes);

        $parts = [
            'codes'  => $codes,
            'days'   => $days,
            'lat'    => round($lat, 2),
            'lng'    => round($lng, 2),
            'radius' => round($radiusKm),
            'stay'   => $stay,
            // How they arrive reshapes day 1, so two travellers only share an
            // answer when they arrive the same way at roughly the same time.
            // The hour, not the minute: 13:40 and 13:55 want the same plan,
            // and keying on the minute would make the cache useless.
            'arrival' => $arrival === null ? null : [
                'mode' => $arrival['mode'] ?? null,
                'from' => $arrival['site_id'] ?? null,
                'hour' => substr((string) ($arrival['at'] ?? ''), 0, 2),
            ],
            // Who is going reshapes every day, not just the first: a family
            // with a toddler is held to fewer stops and is written for
            // differently. Two parties only share an answer when they would
            // have been given the same one.
            'group' => $group,
        ];

        // Anything they typed. Almost every note is unique, so a trip that
        // carries one will usually miss — correct, not a regression: the
        // answer it would have hit was written for someone who said
        // something else.
        //
        // ADDED ONLY WHEN THERE IS ONE, rather than as a permanent `null`
        // member of the array. A new key with a null value still changes the
        // encoded string, so every entry already in the table would become
        // unreachable the moment this deployed — a silent, paid-for cache
        // flush for a field most travellers will never fill in. This way a
        // note-less trip hashes byte-identically to the way it hashed
        // before the field existed.
        if (($note = ItineraryPrompt::note($travellerNote)) !== null) {
            $parts['note'] = $note;
        }

        return hash('sha256', json_encode($parts));
    }

    /**
     * A cached answer, or null. Expiry is checked here rather than by a
     * scheduled sweep, so a stale entry can never be served even if nothing
     * has cleaned up.
     */
    public function get(string $hash): ?AiItineraryCache
    {
        if (!$this->enabled()) {
            return null;
        }

        return AiItineraryCache::where('filter_hash', $hash)->unexpired()->first();
    }

    /** Record that an entry was used. The hit rate is a metric the panel reads. */
    public function hit(AiItineraryCache $entry): void
    {
        $entry->forceFill([
            'hits'        => $entry->hits + 1,
            'last_hit_at' => now(),
        ])->save();
    }

    /**
     * Keep an answer. Does nothing when caching is switched off, so the
     * feature can be disabled in one config row without a deploy.
     *
     * @param  array<string,mixed>  $response  what the model returned
     * @param  array<int,int>       $siteIds   the places the answer named
     */
    public function put(
        string $hash,
        array $response,
        array $siteIds,
        int $days,
        float $costInr
    ): void {
        if (!$this->enabled()) {
            return;
        }

        $ttl = (int) AiConfig::get('cache_ttl_hours', 168);

        AiItineraryCache::updateOrCreate(
            ['filter_hash' => $hash],
            [
                'response'   => $response,
                'site_ids'   => array_values($siteIds),
                'days'       => $days,
                'cost_inr'   => $costInr,
                'expires_at' => $ttl > 0 ? now()->addHours($ttl) : null,
            ]
        );
    }

    /**
     * Throw an entry away. Called when a cached answer no longer validates —
     * usually because a place in it has closed — so the next traveller gets a
     * fresh plan rather than the same broken one.
     */
    public function forget(string $hash): void
    {
        AiItineraryCache::where('filter_hash', $hash)->delete();
    }

    /**
     * Drop every cached answer that names this place.
     *
     * Not required for correctness — reassembly already removes a place that
     * has left the candidate set — but it turns the next traveller's silent
     * revalidation into a straightforward miss, and it is what an admin
     * expects to happen when they reject a listing.
     */
    public function forgetSite(int $siteId): int
    {
        return AiItineraryCache::whereJsonContains('site_ids', $siteId)->delete();
    }

    /**
     * Hit rate and what caching has saved, for the overview screen.
     *
     * @return array{entries: int, hits: int, saved_inr: float}
     */
    public function stats(): array
    {
        /** @var Collection<int,AiItineraryCache> $rows */
        $rows = AiItineraryCache::unexpired()->get(['hits', 'cost_inr']);

        return [
            'entries' => $rows->count(),
            'hits'    => (int) $rows->sum('hits'),
            // Each hit avoided one generation at that entry's own cost, which
            // is a measurement rather than an estimate.
            'saved_inr' => round((float) $rows->sum(fn ($r) => $r->hits * (float) $r->cost_inr), 2),
        ];
    }

    private function enabled(): bool
    {
        return AiConfig::getBool('cache_enabled', true);
    }
}
