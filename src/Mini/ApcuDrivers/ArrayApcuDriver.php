<?php
namespace mini\Mini\ApcuDrivers;

/**
 * ArrayApcuDriver - Simple in-memory APCu polyfill using static array
 *
 * Provides APCu-compatible caching when neither APCu extension nor Swoole is available.
 * Data is stored in a static array and persists only for the lifetime of the PHP process.
 *
 * Limitations:
 *   - Not shared between PHP-FPM workers or processes
 *   - Cleared on each request in PHP-FPM (but persists in CLI/long-running processes)
 *   - No memory limits (can grow unbounded)
 *   - TTL cleanup only happens on access (lazy expiration)
 *
 * Best used as a fallback or for testing when real APCu is unavailable.
 */
class ArrayApcuDriver implements ApcuDriverInterface
{
    use ApcuDriverTrait;

    /**
     * In-memory storage: key => payload
     * Static so it persists across multiple driver instances in the same process.
     *
     * @var array<string, string>
     */
    private static array $data = [];

    /* --------------------------------------------------------------------
     * LOW-LEVEL BACKEND PRIMITIVES FOR ApcuDriverTrait
     * ------------------------------------------------------------------ */

    /**
     * Fetch raw payload from static array.
     */
    protected function _fetch(string $key, bool &$found = null): ?string
    {
        $found = array_key_exists($key, self::$data);
        return $found ? self::$data[$key] : null;
    }

    /**
     * Atomic "add if not exists" (SETNX) - single-threaded, so no actual locking needed.
     */
    protected function _add(string $key, string $payload, int $ttl): bool
    {
        if (array_key_exists($key, self::$data)) {
            return false; // Already exists
        }

        self::$data[$key] = $payload;
        return true;
    }

    /**
     * Unconditional overwrite (SET).
     */
    protected function _store(string $key, string $payload, int $ttl): bool
    {
        self::$data[$key] = $payload;
        return true;
    }

    /**
     * Delete a key.
     */
    protected function _delete(string $key): bool
    {
        if (array_key_exists($key, self::$data)) {
            unset(self::$data[$key]);
            return true;
        }
        return false;
    }

    /* --------------------------------------------------------------------
     * REQUIRED ApcuDriverInterface METHODS NOT PROVIDED BY THE TRAIT
     * ------------------------------------------------------------------ */

    /**
     * apcu_cache_info(): return basic stats about the array cache.
     */
    public function info(bool $limited = false): array|false
    {
        $numEntries = count(self::$data);
        $memoryUsage = 0;

        // Estimate memory usage (rough approximation)
        foreach (self::$data as $key => $payload) {
            $memoryUsage += strlen($key) + strlen($payload);
        }

        return [
            'num_entries'  => $numEntries,
            'memory_usage' => $memoryUsage,
            'limited'      => $limited,
            'driver'       => 'array',
        ];
    }

    /**
     * apcu_sma_info(): no real allocator, return stub.
     */
    public function sma_info(bool $limited = false): array|false
    {
        return [
            'available_memory' => null,
            'used_memory'      => $this->info()['memory_usage'] ?? 0,
            'num_seg'          => 1,
            'seg_size'         => null,
            'limited'          => $limited,
            'driver'           => 'array',
        ];
    }

    /**
     * apcu_clear_cache(): wipe the static array.
     */
    public function clear_cache(): bool
    {
        self::$data = [];
        return true;
    }

    /**
     * apcu_enabled(): this driver is always enabled (no dependencies).
     */
    public function enabled(): bool
    {
        return true;
    }
}
