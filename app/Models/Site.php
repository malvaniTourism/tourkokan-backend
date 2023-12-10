<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;

class Site extends Model
{
    use HasFactory, Hashidable, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'parent_id',
        'user_id',
        'category_id',
        'bus_stop_type',
        'tag_line',
        'description',
        'domain_name',
        'logo',
        'icon',
        'image',
        'status',
        'is_hot_place',
        'latitude',
        'longitude',
        'pin_code',
        'speciality',
        'rules',
        'social_media',
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
        'speciality' => 'array',
        'rules' => 'array',
        'social_media' => 'array',
        'meta_data' => 'array',
    ];

    /**
     * Get the site that owns the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function site()
    {
        return $this->belongsTo(Site::class, 'parent_id', 'id');
    }

    /**
     * Get all of the sites for the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sites()
    {
        return $this->hasMany(Site::class, 'parent_id');
    }

    /**
     * Get the category that owns the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
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
     * Get all of the comments for the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    }

    /**
     * Get all of the address's projects.
     */
    public function rateable()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    /**
     * Get all of the contact's comments.
     */
    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function favorites()
    {
        return $this->morphMany(Favourite::class, 'favouritable');
    }
}
