<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Hashidable;

class Address extends Model
{
    use HasFactory, Hashidable, Notifiable;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'address1',
        'address2',
        'address3',
        'block',
        'landmark',
        'country',
        'state',
        'district',
        'city',
        'place',
        'site_id',
        'pincode',
        'latitude',
        'longitude',
        'addressable_type',
        'addressable_id',
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
     * Get all of the models that own contacts.
     */
    public function addressable()
    {
        return $this->morphTo();
    }
}
