<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Hashidable;

class Event extends Model
{
    use HasFactory, SoftDeletes, Hashidable;

    protected $fillable = [
        'user_id', 'site_id', 'event_type_id', 'status', 'rejection_reason',
        'title', 'slug', 'description',
        'organizer_name', 'organizer_phone', 'organizer_email',
        'contact_person_name', 'contact_person_phone',
        'venue_name', 'address', 'taluka', 'latitude', 'longitude',
        'start_date', 'end_date', 'start_time', 'end_time', 'is_multi_day', 'timezone',
        'banner_image', 'gallery', 'video_url',
        'is_free', 'entry_fee', 'registration_required', 'registration_link',
        'registration_deadline', 'max_participants', 'tags',
        'view_count', 'click_count', 'share_count', 'like_count',
        'favourite_count', 'going_count', 'interested_count',
        'is_featured', 'featured_until', 'is_sponsored', 'sponsor_name',
        'notification_sent', 'reminder_sent',
        'approved_by', 'approved_at', 'admin_notes',
    ];

    protected $casts = [
        'start_date'            => 'date',
        'end_date'              => 'date',
        'registration_deadline' => 'date',
        'approved_at'           => 'datetime',
        'featured_until'        => 'datetime',
        'gallery'               => 'array',
        'tags'                  => 'array',
        'is_multi_day'          => 'boolean',
        'is_free'               => 'boolean',
        'is_featured'           => 'boolean',
        'is_sponsored'          => 'boolean',
        'registration_required' => 'boolean',
        'notification_sent'     => 'boolean',
        'reminder_sent'         => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function interactions()
    {
        return $this->hasMany(EventInteraction::class);
    }

    public function notifications()
    {
        return $this->hasMany(EventNotification::class);
    }

    public function favourites()
    {
        return $this->morphMany(Favourite::class, 'favouritable');
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now()->toDateString());
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)
            ->where(function ($q) {
                $q->whereNull('featured_until')
                  ->orWhere('featured_until', '>=', now());
            });
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // ── Accessors ──────────────────────────────────────────────────

    public function getIsActiveAttribute()
    {
        return $this->status === 'approved' && $this->end_date >= now()->toDateString();
    }

    public function getCountdownDaysAttribute()
    {
        return now()->diffInDays($this->start_date, false);
    }
}
