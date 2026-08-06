<?php

namespace App\Models;

use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor listing attached to one of their approved sites.
 *
 * Ownership is derived — `$product->site->user_id` — never stored here. See
 * docs/VENDOR_PRODUCTS_DESIGN.md §2.3.
 */
class Product extends Model
{
    use HasFactory, Hashidable, SoftDeletes;

    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'paused'];

    /**
     * R4 — a fixed vocabulary, because booking maths depends on it. `per_night` combined
     * with a category's `date_range` booking_type is what makes nightly calculation
     * possible without a data cleanup. See design doc §3.
     */
    public const UNITS = [
        'per_night', 'per_person', 'per_plate', 'per_kg',
        'per_hour', 'per_piece', 'per_package',
    ];

    protected $fillable = [
        'site_id',
        'product_category_id',
        'name',
        'mr_name',
        'slug',
        'description',
        'mr_description',
        'attributes',
        'base_price',
        'sale_price',
        'currency',
        'unit',
        'is_bookable',
        'status',
        'rejection_reason',
        'is_featured',
        'available_from',
        'available_to',
        'sort_order',
    ];

    protected $casts = [
        'attributes'     => 'array',
        'base_price'     => 'decimal:2',
        'sale_price'     => 'decimal:2',
        'is_bookable'    => 'boolean',
        'is_featured'    => 'boolean',
        'available_from' => 'date',
        'available_to'   => 'date',
        'views_count'    => 'integer',
        'leads_count'    => 'integer',
        'sort_order'     => 'integer',
    ];

    // ── Relations ────────────────────────────────────────────────────────────────

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function productCategory()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('sort_order');
    }

    public function defaultVariant()
    {
        return $this->hasOne(ProductVariant::class, 'product_id')->where('is_default', true);
    }

    public function gallery()
    {
        return $this->morphMany(Gallery::class, 'galleryable')->orderBy('sort_order');
    }

    public function cover()
    {
        return $this->morphOne(Gallery::class, 'galleryable')->where('is_cover', true);
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id');
    }

    public function ratings()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function favourites()
    {
        return $this->morphMany(Favourite::class, 'favouritable');
    }

    public function contacts()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    // ── Pricing ──────────────────────────────────────────────────────────────────

    /**
     * The authoritative sell price.
     *
     * R1 — resolved through the default variant, never off `products.base_price`, which is
     * a display value only ("from ₹1,200"). When the availability calendar lands, a
     * product_availability.price_override slots in above the variant and every caller of
     * this accessor picks it up for free. Reading `base_price` directly is what would make
     * that change a rewrite. See docs/VENDOR_PRODUCTS_DESIGN.md §3.
     */
    public function getPriceAttribute(): ?string
    {
        $variant = $this->relationLoaded('defaultVariant')
            ? $this->defaultVariant
            : $this->defaultVariant()->first();

        return $variant?->sale_price ?? $variant?->price ?? $this->sale_price ?? $this->base_price;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────────

    /**
     * Publicly visible: the product is approved, its site is still live, and it is inside
     * its availability window.
     *
     * The site check matters — a vendor can build a catalog while their business is still
     * pending, and an admin can unpublish a site later. Neither must leave listings
     * reachable underneath it.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'approved')
            ->whereHas('site', fn($q) => $q->where('status', true)->where('submission_status', 'approved'))
            ->where(fn($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn($q) => $q->whereNull('available_to')->orWhere('available_to', '>=', now()));
    }

    /** Products belonging to a vendor, across all their outlets. */
    public function scopeOwnedBy($query, $userId)
    {
        return $query->whereHas('site', fn($q) => $q->where('user_id', $userId));
    }

    public function scopeAwaitingReview($query)
    {
        return $query->where('status', 'pending');
    }
}
