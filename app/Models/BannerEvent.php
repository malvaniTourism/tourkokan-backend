<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One counted impression or click against a banner.
 *
 * This is the reporting and audit source; `banners.impressions` / `banners.clicks` are the
 * denormalised display counters kept in step with it. Same split as
 * {@see ProductViewEvent} and `products.views_count`.
 *
 * See docs/banner-tracking-backend-ask.md §1.
 */
class BannerEvent extends Model
{
    use HasFactory;

    /** Only `created_at` is meaningful — an event is never updated. */
    public const UPDATED_AT = null;

    /**
     * Extensible in the same way as {@see ProductLead::TYPES}: a future `conversion` joins
     * this list without a schema change.
     */
    public const TYPES = ['impression', 'click'];

    protected $fillable = [
        'banner_id',
        'user_id',
        'event_type',
        'placement_code',
        'session_hash',
        'platform',
        'ip_hash',
        'dedupe_key',
    ];

    /** Never expose the identity hashes to a client. */
    protected $hidden = ['ip_hash', 'session_hash', 'dedupe_key'];

    public function banner()
    {
        return $this->belongsTo(Banner::class, 'banner_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
