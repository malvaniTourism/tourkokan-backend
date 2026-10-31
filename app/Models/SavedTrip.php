<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A trip. `draft` while it is still a cart the traveller is filling, `generated`
 * once an itinerary has been written against it.
 */
class SavedTrip extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'share_token',
        'is_public',
        'base_site_id',
        'days',
        'filters',
        'itinerary',
        'custom_stops',
    ];

    protected $casts = [
        'filters'      => 'array',
        'itinerary'    => 'array',
        'custom_stops' => 'array',
        'is_public'    => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function baseSite()
    {
        return $this->belongsTo(Site::class, 'base_site_id');
    }

    /**
     * The cart: favourites stamped with this trip. A favourite with a null
     * trip_id is an ordinary favourite and belongs to no trip.
     */
    public function favourites()
    {
        return $this->hasMany(Favourite::class, 'trip_id');
    }

    /**
     * The sites in the cart, in the order they were added.
     */
    public function cartSites()
    {
        return $this->hasManyThrough(
            Site::class,
            Favourite::class,
            'trip_id',
            'id',
            'id',
            'favouritable_id'
        )->where('favourites.favouritable_type', (new Site())->getMorphClass());
    }

    public function scopeDrafts($query)
    {
        return $query->where('status', 'draft');
    }
}
