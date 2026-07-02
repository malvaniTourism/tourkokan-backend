<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BannerPlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'screen',
        'width',
        'height',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function banners()
    {
        return $this->hasMany(Banner::class);
    }
}