<?php

namespace App\Models;

use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Whitelist: which product categories may be listed under a given site category.
 *
 * This is what stops a site categorised "Hospital" from listing Alphonso mangoes, and what
 * the app calls to populate the category picker once a vendor has chosen an outlet.
 *
 * See docs/VENDOR_PRODUCTS_DESIGN.md §2.5.
 */
class AllowedProductCategory extends Model
{
    use HasFactory, Hashidable;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'category_id',
        'product_category_id',
        'is_required',
        'max_products',
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
        'is_required'  => 'boolean',
        'max_products' => 'integer',
    ];

    /**
     * Get the site category that owns the AllowedProductCategory
     * (e.g. "Hotel Rooms", "Restaurant").
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Get the productCategory that owns the AllowedProductCategory
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function productCategory()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }
}
