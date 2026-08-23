<?php
/**
 * Simple in-memory cache for frequently accessed data.
 * Reduces database queries for repeated reads.
 */
final class Cache
{
    private static array $store = [];
    private static int $hits = 0;
    private static int $misses = 0;

    /**
     * Get a cached value or execute the callback and cache the result.
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int $ttl Time to live in seconds (default: 300 = 5 minutes)
     * @return mixed Cached or computed value
     */
    public static function remember(string $key, callable $callback, int $ttl = 300): mixed
    {
        if (isset(self::$store[$key]) && self::$store[$key]['expires'] > time()) {
            self::$hits++;
            return self::$store[$key]['value'];
        }

        self::$misses++;
        $value = $callback();
        self::$store[$key] = [
            'value' => $value,
            'expires' => time() + $ttl,
        ];
        return $value;
    }

    /**
     * Get a cached value without executing callback.
     */
    public static function get(string $key): mixed
    {
        if (isset(self::$store[$key]) && self::$store[$key]['expires'] > time()) {
            self::$hits++;
            return self::$store[$key]['value'];
        }
        self::$misses++;
        return null;
    }

    /**
     * Set a cached value.
     */
    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        self::$store[$key] = [
            'value' => $value,
            'expires' => time() + $ttl,
        ];
    }

    /**
     * Clear a specific cache key.
     */
    public static function forget(string $key): void
    {
        unset(self::$store[$key]);
    }

    /**
     * Clear all cache.
     */
    public static function flush(): void
    {
        self::$store = [];
    }

    /**
     * Get cache statistics.
     */
    public static function stats(): array
    {
        return [
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => self::$hits + self::$misses > 0
                ? round(self::$hits / (self::$hits + self::$misses) * 100, 2)
                : 0,
            'entries' => count(self::$store),
        ];
    }
}
