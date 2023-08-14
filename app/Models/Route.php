<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Traits\Hashidable;

class Route extends Model
{
    use Hashidable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'source_place_id',
        'destination_place_id',
        'bus_type_id',
        'name',
        'description',
        'meta_data',
        'start_time',
        'end_time',
        'total_time',
        'delayed_time'
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
    protected $casts = [
        'departure_time' => 'array',
        'arrival_time' => 'array',
        'total_time' => 'array',
        'delayed_time' => 'array'
    ];

    /**
     * Get the sourcePlace that owns the Route
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sourcePlace()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Get the sourcePlace that owns the Route
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function destinationPlace()
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Get all of the routeStops for the Route
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function routeStops()
    {
        return $this->hasMany(RouteStops::class, 'route_id');
    }

    /**
     * Get the busType that owns the Route
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function busType()
    {
        return $this->belongsTo(BusType::class);
    }
}
