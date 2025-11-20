<?php
namespace mini\Mini\Microcache;

use Closure;

/**
 * High-performance microcache using APCu shared memory.
 *
 * Stores cached values in APCu shared memory, which is shared across all PHP-FPM workers
 * on the same server. Much faster than network-based caches (Redis/Memcached) for small,
 * frequently accessed data.
 *
 * Performance: ~0.001ms (APCu) vs ~0.5-1ms (Redis/Memcached network RTT)
 *
 * @package mini\Mini\Microcache
 */
final class ApcuMicrocache implements MicrocacheInterface {

    /**
     * Fetch a value from the cache. If the value is not available, the `$generatorFunction`
     * is invoked to compute and cache the value.
     *
     * @param string $key Cache key
     * @param Closure(): mixed $generatorFunction Called when cache miss occurs
     * @param float $ttl Time-to-live in seconds (0 = cache forever)
     * @return int|float|string|bool|array|null Cached or generated value
     * @throws \Throwable Any exception thrown by $generatorFunction
     */
    public function fetch(string $key, Closure $generatorFunction, float $ttl = 0): int|float|string|bool|array|null {
        // Check APCu cache
        if (apcu_exists($key)) {
            return apcu_fetch($key);
        }

        // Cache miss - generate value
        $value = $generatorFunction();

        // Store in APCu
        apcu_store($key, $value, (int)$ttl);

        return $value;
    }

}
