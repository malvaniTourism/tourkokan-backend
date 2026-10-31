<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AiConfig extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'description'];

    private const CACHE_PREFIX = 'ai_config:';
    private const CACHE_TTL    = 3600; // 1 hour

    /**
     * Read a config value by key (cached). Returns $default when missing.
     */
    public static function get(string $key, $default = null)
    {
        $value = Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key) {
            return static::query()->where('key', $key)->value('value');
        });

        return $value ?? $default;
    }

    /**
     * Boolean-typed convenience reader (treats "1"/"true"/"yes"/"on" as true).
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Integer-typed convenience reader.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = static::get($key);

        return $value === null ? $default : (int) $value;
    }

    /**
     * Create or update a config value, busting its cache.
     */
    public static function set(string $key, $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
    }

    protected static function booted(): void
    {
        static::saved(fn (self $config) => Cache::forget(self::CACHE_PREFIX . $config->key));
        static::deleted(fn (self $config) => Cache::forget(self::CACHE_PREFIX . $config->key));
    }
}
