<?php
namespace mini\Mini\Microcache;

use Closure;

/**
 * Ultra-fast local caching interface for data where network round-trip (Redis/Memcached)
 * would be slower than fetching from local sources.
 *
 * Typical use cases:
 * - Parsed configuration files
 * - Route lookup tables
 * - Translation files
 * - Database schema metadata
 * - Compiled templates
 *
 * Performance comparison:
 * - Memcached RTT: ~0.5-1ms (network round-trip)
 * - APCu: ~0.001ms (shared memory)
 * - Process memory: ~0.0001ms (already in-process)
 *
 * @package mini\Mini\Microcache
 */
interface MicrocacheInterface {

    /**
     * Fetch a value from the cache. If the value is not available, the `$generatorFunction`
     * is invoked to compute and cache the value.
     *
     * The generator function is called lazily only on cache miss. If it throws an exception,
     * that exception bubbles up to the caller and nothing is cached.
     *
     * @param string $key Cache key
     * @param Closure(): mixed $generatorFunction Called when cache miss occurs
     * @param float $ttl Time-to-live in seconds (0 = cache forever, default)
     * @return int|float|string|bool|array|null Cached or generated value
     * @throws \Throwable Any exception thrown by $generatorFunction
     */
    public function fetch(string $key, Closure $generatorFunction, float $ttl = 0): int|float|string|bool|array|null;

}
