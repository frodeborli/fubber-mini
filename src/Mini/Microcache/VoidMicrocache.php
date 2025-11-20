<?php
namespace mini\Mini\Microcache;

use Closure;

/**
 * No-op microcache implementation that never caches anything.
 *
 * Always invokes the generator function on every fetch() call.
 * Used as fallback when APCu is not available and you want zero caching overhead.
 *
 * @package mini\Mini\Microcache
 */
final class VoidMicrocache implements MicrocacheInterface {

    /**
     * Always invokes the generator function - never caches.
     *
     * @param string $key Ignored (never cached)
     * @param Closure(): mixed $generatorFunction Always invoked
     * @param float $ttl Ignored (nothing to expire)
     * @return int|float|string|bool|array|null Generated value
     * @throws \Throwable Any exception thrown by $generatorFunction
     */
    public function fetch(string $key, Closure $generatorFunction, float $ttl = 0): int|float|string|bool|array|null {
        return $generatorFunction();
    }

}
