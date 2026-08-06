<?php

namespace App\Models;

use App\Traits\Hashidable;
use App\Traits\HasStorageFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCategory extends Model
{
    use HasFactory, Hashidable, HasStorageFiles, SoftDeletes;

    /** Returned as full URLs on read; only the storage path is persisted. */
    protected array $fileFields = ['icon'];

    protected $fillable = [
        'parent_id',
        'name',
        'mr_name',
        'code',
        'slug',
        'description',
        'icon',
        'attribute_schema',
        'booking_type',
        'status',
        'sort_order',
        'meta_data',
    ];

    protected $hidden = [];

    protected $casts = [
        'attribute_schema' => 'array',
        'meta_data'        => 'array',
        'status'           => 'boolean',
        'sort_order'       => 'integer',
    ];

    /**
     * How this category is sold, once booking exists.
     *
     *   none        enquiry-only listing (default — everything at launch)
     *   date_range  accommodation: check-in → check-out         (calendar)
     *   slot        activities / water sports: fixed time slots (calendar)
     *   quantity    physical goods: plain stock, no calendar
     *
     * BOOKING-READY (R2): nothing reads this yet. It is seeded correctly from day one so
     * that adding the availability calendar is a pure addition — no re-seed, no backfill.
     * See docs/VENDOR_PRODUCTS_DESIGN.md §3.
     */
    public const BOOKING_TYPES = ['none', 'date_range', 'slot', 'quantity'];

    /**
     * Attribute types a category schema may declare. Consumed by
     * {@see \App\Services\ProductAttributeValidator} and by the app's dynamic form renderer.
     */
    public const ATTRIBUTE_TYPES = [
        'string', 'text', 'int', 'decimal', 'bool', 'enum', 'multi', 'date', 'time',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Site categories this product category may be listed under.
     */
    public function allowedCategories()
    {
        return $this->hasMany(AllowedProductCategory::class, 'product_category_id');
    }

    /**
     * The site categories themselves, through the whitelist pivot.
     */
    public function siteCategories()
    {
        return $this->belongsToMany(
            Category::class,
            'allowed_product_categories',
            'product_category_id',
            'category_id'
        )->withPivot(['is_required', 'max_products'])->withTimestamps();
    }

    // NOTE: products() is reinstated in Phase 4 when the `products` table is rebuilt.
    // See docs/VENDOR_PRODUCTS_DESIGN.md §5.2.

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
