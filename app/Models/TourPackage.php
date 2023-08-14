<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;

class TourPackage extends Model
{
    use HasFactory, Hashidable, HasFactory, Notifiable;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'user_id',
        'title',
        'tag_line',
        'description',
        'image_url',
        'duration',
        'dates',
        'price',
        'rules',
        'ambience',
        'includes',
        'itinerary',
        'contact_details',
        'social_media',
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
        // 'duration'   => 'json',
        // 'dates'  => 'json',
        // 'price'  => 'json',
        // 'rules'  => 'json',
        // 'ambience'   => 'json',
        // 'includes'   => 'json',
        // 'itenarary'  => 'json',
        // 'contact_details'    => 'json',
        // 'social_media'   => 'json',
    ];
    
    /**
     * Get all of the models that own products.
     */
    public function products(){
        return $this->morphOne(Product::class, 'productable'); 
    }

    /**
    * Get all of the Food's comments.
    */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    }

}
