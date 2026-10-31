<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A place's appearance in a generated itinerary.
 *
 * Read by the vendor dashboard ("appeared in 40 itineraries") and by the
 * admin placement audit. Never exposes who the traveller was — a vendor sees
 * totals only.
 */
class TripPlacement extends Model
{
    use HasFactory;

    protected $fillable = ['site_id', 'trip_id', 'user_id', 'day', 'position'];

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function trip()
    {
        return $this->belongsTo(SavedTrip::class, 'trip_id');
    }

    /** Appearances for one business since a date. */
    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeSince($query, $date)
    {
        return $query->where('created_at', '>=', $date);
    }
}
