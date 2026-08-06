<?php

namespace App\Models;

use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tier of vendor quotas. Prices live here; nothing in the enforcement path reads them,
 * so going paid is a data change. See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class Plan extends Model
{
    use HasFactory, Hashidable;

    /** The plan every vendor starts on. */
    public const FREE = 'free';

    protected $fillable = [
        'code', 'name', 'mr_name', 'description',
        'price', 'currency', 'billing_period',
        'limits', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'limits'     => 'array',
        'price'      => 'decimal:2',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions()
    {
        return $this->hasMany(VendorSubscription::class, 'plan_id');
    }

    /**
     * A quota value. Null means unlimited — and an absent key also means unlimited, so a
     * plan that predates a new limit stays permissive rather than locking vendors out.
     */
    public function limit(string $key): ?int
    {
        $value = ($this->limits ?? [])[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
