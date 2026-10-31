<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One generation attempt. Written whether it succeeded or not — a failure that
 * returns the user's credit is exactly the row an admin needs to see.
 */
class AiLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trip_id',
        'type',
        'status',
        'input_filters',
        'candidates_sent',
        'prompt_hash',
        'driver',
        'model',
        'tokens_in',
        'tokens_out',
        'cost_inr',
        'latency_ms',
        'ai_response',
        'dropped_site_ids',
        'valid_sites_used',
        'days_generated',
        'failure_reason',
        'error_message',
        'error_trace',
        'is_flagged',
        'flag_reason',
        'admin_reviewed',
        'reviewed_by',
        'reviewed_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'input_filters'    => 'array',
        'ai_response'      => 'array',
        'dropped_site_ids' => 'array',
        'is_flagged'       => 'boolean',
        'admin_reviewed'   => 'boolean',
        'reviewed_at'      => 'datetime',
        'cost_inr'         => 'decimal:4',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function trip()
    {
        return $this->belongsTo(SavedTrip::class, 'trip_id');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    /**
     * Rupees spent since midnight — what the daily ceiling is measured against.
     */
    public static function spentToday(): float
    {
        return (float) static::whereDate('created_at', today())->sum('cost_inr');
    }
}
