<?php

namespace App\Models;

use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A sellable variation of a product — "Deluxe AC Room", "1 kg box", "Half plate".
 *
 * R1 — every product has at least one variant, auto-created as the default when a vendor
 * supplies only a single price. The variant, not the product, carries the authoritative
 * price, so a future product_availability.price_override has somewhere to attach without
 * rewriting every price read. See docs/VENDOR_PRODUCTS_DESIGN.md §3.
 */
class ProductVariant extends Model
{
    use HasFactory, Hashidable;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'price',
        'sale_price',
        'stock',
        'min_order_qty',
        'max_order_qty',
        'attributes',
        'is_default',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price'      => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock'         => 'integer',
        'min_order_qty' => 'integer',
        'max_order_qty' => 'integer',
        'is_default'    => 'boolean',
        'status'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** null stock means "not stock-tracked", which is not the same as out of stock. */
    public function getIsInStockAttribute(): bool
    {
        return $this->stock === null || $this->stock > 0;
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
