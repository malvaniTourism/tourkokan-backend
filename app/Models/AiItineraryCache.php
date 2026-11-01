<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cached answer from the model.
 *
 * See the migration for why this holds the model's decision rather than a
 * finished itinerary: the places are refetched and revalidated on every hit,
 * so correctness does not rest on anyone remembering to invalidate.
 */
class AiItineraryCache extends Model
{
    protected $table = 'ai_itinerary_cache';

    protected $fillable = [
        'filter_hash',
        'response',
        'site_ids',
        'days',
        'hits',
        'last_hit_at',
        'cost_inr',
        'expires_at',
    ];

    protected $casts = [
        'response'    => 'array',
        'site_ids'    => 'array',
        'last_hit_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    /** Entries still inside their TTL. A null expiry never goes stale. */
    public function scopeUnexpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
