<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_type',
        'entity_type',
        'entity_id',
        'entity_name',
        'route',
        'method',
        'ip_address',
        'user_agent',
        'platform',
        'app_version',
        'success',
        'response_time_ms',
        'meta_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'success'   => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('created_at', $date);
    }

    public function scopeInDateRange($query, ?string $from, ?string $to)
    {
        return $query
            ->when($from, fn($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('created_at', '<=', $to));
    }
}
