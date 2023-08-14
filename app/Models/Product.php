<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;

class Product extends Model
{
    use HasFactory, Hashidable, HasFactory, Notifiable;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'project_id',
        'product_category_id',
        'productable_type',
        'productable_id',
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
        'meta_data' => 'json'
    ];

    /**
     * Get the projects that owns the Products
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Projects::class, 'project_id');
    }

    /**
     * Get the productCategory that owns the Products
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function productCategory()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * Get all of the models that own products.
     */
    public function productable()
    {
        return $this->morphTo();
    }
    
    // /**
    // * Get all of the product's comments.
    // */
    // public function comments()
    // {
    //     return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    // }

     /**
     * Get all of the contact's comments.
     */
    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }
}
