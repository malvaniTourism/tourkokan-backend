<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'banner_package_id',
        'banner_placement_id',
        'title',
        'image_url',
        'redirect_url',
        'start_date',
        'end_date',
        'status',
        'impressions',
        'clicks',
        'is_active',
        'name',
        'image',
        'duration',
        'level',
        'image_orientation',
        'meta_data',
        'bannerable_type',
        'bannerable_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function package()
    {
        return $this->belongsTo(BannerPackage::class, 'banner_package_id');
    }

    public function placement()
    {
        return $this->belongsTo(BannerPlacement::class, 'banner_placement_id');
    }

    public function bannerable()
    {
        return $this->morphTo();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where('status', 'approved')
                     ->whereDate('start_date', '<=', now())
                     ->whereDate('end_date', '>=', now());
    }
}