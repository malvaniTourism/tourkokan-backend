<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Traits\Hashidable;

class BusType extends Model
{
    use Hashidable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'type',
        'logo',
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
    protected $casts = [];

    /**
     * Get all of the Buses for the BusType
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function Buses()
    {
        return $this->hasMany(Bus::class, 'bus_type_id');
    }
}
