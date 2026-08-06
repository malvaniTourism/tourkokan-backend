<?php

namespace App\Models;

use App\Traits\Hashidable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tourist reaching out to a vendor about a listing.
 *
 * This is the billable signal — vendors do not feel an impression, they feel a phone call.
 * Views are tracked as value proof; leads are what the pricing model charges for.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class ProductLead extends Model
{
    use HasFactory, Hashidable;

    /**
     * R6 — extensible. `booking_request` joins this list when the availability calendar
     * ships, with no schema change: a booking is a lead that converted.
     */
    public const TYPES = ['call', 'whatsapp', 'directions', 'enquiry'];

    protected $fillable = [
        'product_id',
        'user_id',
        'lead_type',
        'message',
        'platform',
        'ip_hash',
    ];

    protected $hidden = ['ip_hash'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
