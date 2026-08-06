<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw view log, pruned at 90 days and rolled up nightly into product_daily_stats (Phase 6).
 *
 * Append-only: no updated_at, and nothing reads an individual row. Kept separate from
 * product_leads because the two are billed differently and retained for different lengths of
 * time. See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class ProductViewEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'user_id',
        'session_hash',
        'platform',
        'referrer',
        'ip_hash',
    ];

    protected $hidden = ['ip_hash'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
