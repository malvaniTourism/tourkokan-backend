<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushNotificationToken extends Model
{
    protected $fillable = [
        'user_id', 'token', 'platform', 'device_id', 'device_name',
        'is_active', 'last_used_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
