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
    protected $hidden = [
        'mr_name'
    ];

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

    public function getNameAttribute($value)
    {
        $language = config('language');

        // return $language === 'en' ? $value :  ($this->mr_name == "" ? $value :  $this->mr_name);
        return empty($language) || $language === 'en' ? $value : ($this->mr_name == "" ? $value : $this->mr_name);
    }

    public function getMrNameAttribute($value)
    {
        return $value;
    }

    /**
     * Get the site that owns the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function site()
    {
        return $this->belongsTo(Site::class);
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

    // /**
    //  * Get the category that owns the Site
    //  *
    //  * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
    //  */
    // public function category()
    // {
    //     return $this->belongsTo(Category::class, 'category_id', 'id');
    // }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
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
     * Get all of the comment for the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comment()
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    }

    /**
     * Get all of the address's projects.
     */
    public function rating()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    /**
     * Get all of the address's projects.
     */
    public function rate()
    {
        return $this->morphOne(Rating::class, 'rateable')->where('user_id', config('user_id'));
    }

    /**
     * Get all of the contact's comments.
     */
    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function favourites()
    {
        return $this->morphMany(Favourite::class, 'favouritable');
    }

    public function address()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function banners()
    {
        return $this->morphMany(Banner::class, 'bannerable');
    }

    /**
     * Get all of the comment for the Site
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gallery()
    {
        return $this->morphMany(Gallery::class, 'galleryable');
    }
}
