<?php

namespace mini;

use mini\Cache\NamespacedCache;
use Psr\SimpleCache\CacheInterface;

// Register SimpleCache service when this file is loaded
if (!Mini::$mini->has(CacheInterface::class)) {
    Mini::$mini->addService(CacheInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(CacheInterface::class));
}

/**
 * Get cache instance
 *
 * Returns PSR-16 SimpleCache instance from container.
 * With smart fallback: APCu > SQLite in /tmp > Filesystem in /tmp
 *
 * @param string|null $namespace Optional namespace for cache isolation
 * @return \Psr\SimpleCache\CacheInterface Cache instance
 */
function cache(?string $namespace = null): CacheInterface {
    $cache = Mini::$mini->get(CacheInterface::class);

    // Return namespaced cache if namespace provided
    if ($namespace !== null) {
        return new NamespacedCache($cache, $namespace);
    }

    return $cache;
}
