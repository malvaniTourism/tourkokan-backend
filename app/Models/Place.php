<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;

class Place extends Model
{
    use HasFactory, Hashidable, Notifiable;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'city_id',
        'parent_id',
        'place_category_id',
        'description',
        'rules',
        'image_url',
        'bg_image_url',
        'price',
        'rating',
        'visitors_count',
        'social_media',
        'contact_details',
        'latitude',
        'longitude',
        'meta_data',
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
        'rules' => 'array',
        'price' => 'array',
        'social_media' => 'array',
        'contact_details' => 'array'
    ];

    /**
     * Get the city that owns the Place
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Get all of the photos for the Place
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function photos()
    {
        return $this->hasMany(Photos::class, 'place_id');
    }  
    
    /**
     * Get the placeCategory that owns the Place
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function placeCategory()
    {
        return $this->belongsTo(PlaceCategory::class);
    }

    // /**
    //  * Get all of the plce's comments.
    //  */
    // public function comments()
    // {
    //     return $this->morphMany(Comment::class, 'commentable');
    // }

    /**
    * Get all of the product's comments.
    */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    }

     /**
     * Get all of the contact's comments.
     */
    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    /**
     * Get all of the address's projects.
     */
    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get all of the address's projects.
     */
    public function rateable()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    /**
     * Get all of the routes for the Place
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function routes()
    {
        return $this->hasMany(Route::class, 'source_place_id');
    }
}
