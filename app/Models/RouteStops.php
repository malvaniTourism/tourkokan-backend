<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Traits\Hashidable;

class RouteStops extends Model
{
    use Hashidable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'serial_no',
        'route_id',
        'site_id',
        'arr_time',
        'dept_time',
        'total_time',
        'delayed_time',
        'meta_data'
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
        'meta_data' => 'array'
    ];

     /**
     * Get the sourcePlace that owns the Route
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sites()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /**
     * Get the routes that owns the RouteStops
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function routes()
    {
        return $this->belongsTo(Route::class, 'site_id');
    }

    /**
     * Get the place that owns the RouteStops
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
