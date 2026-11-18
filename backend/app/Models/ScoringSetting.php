<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Admin overrides for config/scoring.php.
 *
 * `key` is a dot path into the config (e.g. "weights.budget", "bands.hot",
 * "ai_weight"), `value` is any JSON value. Use ScoringSetting::config() everywhere
 * scoring reads settings: it returns config/scoring.php with overrides merged on top.
 */
class ScoringSetting extends Model
{
    public const CACHE_KEY = 'scoring_settings.overrides';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * Effective scoring config (file config + cached DB overrides).
     */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        $config = config('scoring');

        foreach (static::overrides() as $path => $value) {
            Arr::set($config, $path, $value);
        }

        return $key === null ? $config : Arr::get($config, $key, $default);
    }

    /** @return array<string, mixed> dot-path => value */
    public static function overrides(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            return []; // table not migrated yet (fresh install) — fall back to file config, don't cache
        }
    }

    public static function put(string $key, mixed $value): self
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
