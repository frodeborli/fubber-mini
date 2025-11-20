<?php
namespace mini\Mini\Microcache;

use Closure;

/**
 * High-performance microcache using APCu shared memory.
 *
 * Two-tier caching strategy:
 * 1. Process memory (static array) - fastest, cleared per-process
 * 2. APCu shared memory - fast, shared across PHP-FPM workers
 *
 * Performance characteristics:
 * - First fetch: APCu lookup (~0.001ms) + promote to process memory
 * - Subsequent fetches in same process: static array (~0.0001ms)
 * - Cache miss: generator invoked, stored in both tiers
 *
 * @package mini\Mini\Microcache
 */
final class ApcuMicrocache implements MicrocacheInterface {

    /**
     * Process-level cache (fastest tier).
     * Static property survives for lifetime of PHP process.
     *
     * @var array<string, mixed>
     */
    private static array $memory = [];

    /**
     * Fetch a value from the cache. If the value is not available, the `$generatorFunction`
     * is invoked to compute and cache the value.
     *
     * Cache lookup order:
     * 1. Process memory (static array) - instant
     * 2. APCu shared memory - microseconds
     * 3. Generator function - as expensive as the operation
     *
     * @param string $key Cache key
     * @param Closure(): mixed $generatorFunction Called when cache miss occurs
     * @param float $ttl Time-to-live in seconds (0 = cache forever)
     * @return int|float|string|bool|array|null Cached or generated value
     * @throws \Throwable Any exception thrown by $generatorFunction
     */
    public function fetch(string $key, Closure $generatorFunction, float $ttl = 0): int|float|string|bool|array|null {
        // L1: Process memory (fastest - no syscall)
        if (array_key_exists($key, self::$memory)) {
            return self::$memory[$key];
        }

        // L2: APCu (fast - shared memory syscall)
        if (apcu_exists($key)) {
            $value = apcu_fetch($key);
            self::$memory[$key] = $value; // Promote to L1
            return $value;
        }

        // Cache miss - generate value
        $value = $generatorFunction();

        // Store in both tiers
        self::$memory[$key] = $value;
        apcu_store($key, $value, (int)$ttl);

        return $value;
    }

}
