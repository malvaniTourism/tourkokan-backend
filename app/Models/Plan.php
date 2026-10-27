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

    /**
     * Months of cover each billing period buys. The subscription's end date is derived from
     * this, not hard-coded at enrolment — so setting a plan's billing_period in admin is what
     * controls how long a subscription lasts. A period not listed here (e.g. 'free') has no
     * fixed length: the subscription never expires (null end date).
     */
    public const BILLING_MONTHS = [
        'monthly'   => 1,
        'quarterly' => 3,
        'yearly'    => 12,
    ];

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

    /**
     * How many months a subscription on this plan runs, from its billing period.
     * Null means it never expires (e.g. a 'free' period, or any unrecognised value).
     */
    public function durationMonths(): ?int
    {
        return self::BILLING_MONTHS[$this->billing_period] ?? null;
    }
}
