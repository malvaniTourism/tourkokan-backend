<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ProductCategory extends Model
{
    use HasFactory, Hashidable, Notifiable;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'icon',
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
    ];

    public function setCategoryNameAttribute($value){
        $this->attributes['meta_data'] = json_encode($value);
    }

    public function getCategoryNameAttribute($value){
        $this->attributes['meta_data'] = json_decode($value, true);
    }

    /**
     * Get all of the allowed site-category mappings for this ProductCategory.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function allowedCategories()
    {
        return $this->hasMany(AllowedProductCategory::class, 'product_category_id');
    }

    // NOTE: products() is reinstated in Phase 4 when the `products` table is rebuilt.
    // See docs/VENDOR_PRODUCTS_DESIGN.md §5.2.
}
