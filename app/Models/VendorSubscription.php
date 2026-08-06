<?php

namespace App\Models;

use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A vendor's current plan. Resolved on every quota check, so it is deliberately cheap to
 * look up. See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class VendorSubscription extends Model
{
    use HasFactory, Hashidable;

    protected $fillable = [
        'user_id', 'plan_id', 'starts_at', 'ends_at',
        'status', 'price_paid', 'auto_renew', 'meta_data',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'price_paid' => 'decimal:2',
        'auto_renew' => 'boolean',
        'meta_data'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** Active and not past its end date. A null ends_at never expires. */
    public function scopeCurrent($query)
    {
        return $query->where('status', 'active')
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    public function getDaysRemainingAttribute(): ?int
    {
        return $this->ends_at === null ? null : max(0, (int) now()->diffInDays($this->ends_at, false));
    }
}
