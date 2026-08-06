<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per product per day. Written by ProductStatsRollup, never by a request.
 *
 * This is the durable record: product_view_events is pruned at 90 days, so anything a
 * vendor or an invoice needs to see beyond that window has to come from here.
 * See docs/VENDOR_PRODUCTS_DESIGN.md §9.
 */
class ProductDailyStat extends Model
{
    protected $fillable = [
        'product_id', 'date',
        'views', 'unique_views', 'leads',
        'leads_call', 'leads_whatsapp', 'leads_directions', 'leads_enquiry',
    ];

    protected $casts = [
        'date'             => 'date',
        'views'            => 'integer',
        'unique_views'     => 'integer',
        'leads'            => 'integer',
        'leads_call'       => 'integer',
        'leads_whatsapp'   => 'integer',
        'leads_directions' => 'integer',
        'leads_enquiry'    => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
