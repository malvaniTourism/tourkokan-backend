<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Hashidable;

/**
 * A favourite — or, when `trip_id` is set, a place in a trip cart.
 *
 * The trip planner reuses this table rather than adding one: a cart is just
 * favourites that share a trip. That was cheap to build and wrong by default,
 * because eight places already read this table and none of them knew the
 * column existed. Ticking a place into a trip made it appear in Favourites,
 * and tapping the heart on it deleted it from the trip.
 *
 * So the safe reading is now the automatic one: every query sees ordinary
 * favourites only. The cart is the exception and says so, via `inTrip()`.
 */
class Favourite extends Model
{
    use HasFactory, Hashidable;

    protected static function booted(): void
    {
        static::addGlobalScope('ordinary', function ($query) {
            $query->whereNull('favourites.trip_id');
        });
    }

    /**
     * Cart rows — the ones the global scope hides. Every trip query goes
     * through here, so "is this a cart read" is visible at the call site.
     */
    public function scopeInTrip($query, ?int $tripId = null)
    {
        $query->withoutGlobalScope('ordinary');

        return $tripId === null
            ? $query->whereNotNull('favourites.trip_id')
            : $query->where('favourites.trip_id', $tripId);
    }

       /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'user_id',
        'trip_id',
        // Without this, mass assignment silently dropped the position a place
        // was added at, so every cart row was created with a null sort_order.
        'sort_order',
        'favouritable_type',
        'favouritable_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [];

     /**
     * Get the users that owns the Favourite
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function users()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all of the models that own comments.
     */
    public function favouritable()
    {
        return $this->morphTo();
    }    
    
}
