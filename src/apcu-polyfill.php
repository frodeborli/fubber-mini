<?php
/**
 * APCu Polyfill
 *
 * Provides apcu_* functions when the APCu extension is not available or when
 * apcu_entry() is missing (APCu < 5.1.0).
 *
 * Driver selection priority (when APCu not available):
 * 1. Swoole\Table (coroutine-safe shared memory)
 * 2. PDO SQLite (persistent across workers)
 * 3. Array fallback (process-scoped only)
 */

use mini\Mini\ApcuDrivers\ApcuDriverFactory;

if (extension_loaded('apcu')) {
    // APCu < 5.1.0 (missing apcu_entry) - polyfill only apcu_entry
    if (!function_exists('apcu_entry')) {
        function apcu_entry(string $key, callable $callback, int $ttl = 0): mixed {
            $success = false;
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }

            $value = $callback();
            apcu_store($key, $value, $ttl);
            return $value;
        }
    }
    return;
}

// Define all apcu_* functions
function apcu_add(string|array $key, mixed $var = null, int $ttl = 0): array|bool {
    return ApcuDriverFactory::getDriver()->add($key, $var, $ttl);
}

function apcu_cache_info(bool $limited = false): array|false {
    return ApcuDriverFactory::getDriver()->info($limited);
}

function apcu_cas(string $key, int $old, int $new): bool {
    return ApcuDriverFactory::getDriver()->cas($key, $old, $new);
}

function apcu_clear_cache(): bool {
    return ApcuDriverFactory::getDriver()->clear_cache();
}

function apcu_dec(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false {
    return ApcuDriverFactory::getDriver()->dec($key, $step, $success, $ttl);
}

function apcu_delete(mixed $key): mixed {
    return ApcuDriverFactory::getDriver()->delete($key);
}

function apcu_enabled(): bool {
    return ApcuDriverFactory::getDriver()->enabled();
}

function apcu_entry(string $key, callable $callback, int $ttl = 0): mixed {
    return ApcuDriverFactory::getDriver()->entry($key, $callback, $ttl);
}

function apcu_exists(string|array $keys): array|bool {
    return ApcuDriverFactory::getDriver()->exists($keys);
}

function apcu_fetch(mixed $key, bool &$success = null): mixed {
    return ApcuDriverFactory::getDriver()->fetch($key, $success);
}

function apcu_inc(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false {
    return ApcuDriverFactory::getDriver()->inc($key, $step, $success, $ttl);
}

function apcu_key_info(string $key): ?array {
    return ApcuDriverFactory::getDriver()->key_info($key);
}

function apcu_sma_info(bool $limited = false): array|false {
    return ApcuDriverFactory::getDriver()->sma_info($limited);
}

function apcu_store(string|array $keys, mixed $var = null, int $ttl = 0): bool|array {
    return ApcuDriverFactory::getDriver()->store($keys, $var, $ttl);
}
