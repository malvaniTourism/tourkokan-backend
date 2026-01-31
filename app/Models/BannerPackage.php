<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BannerPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'duration_days',
        'price',
        'allowed_placements',
        'is_active',
    ];

    protected $casts = [
        'allowed_placements' => 'array',
        'is_active' => 'boolean',
    ];

    public function banners()
    {
        return $this->hasMany(Banner::class);
    }
}