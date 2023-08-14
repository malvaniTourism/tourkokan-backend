<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;

class Food extends Model
{
    use HasFactory, Hashidable, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'food_type',
        'description',
        'nuetritional_info',
        'image_url',
        'visitor_count',
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
        'nuetritional_info' => 'json',
        'meta_data' => 'json'
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
