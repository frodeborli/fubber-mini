<?php

namespace mini\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * APCu-backed PSR-16 SimpleCache implementation
 *
 * Stores cache data in APCu (user cache).
 * Requires APCu extension to be installed and enabled.
 */
class ApcuCache implements CacheInterface
{
    private string $prefix;

    public function __construct(string $prefix = 'mini:')
    {
        $this->prefix = $prefix;
    }

    /**
     * Prefix key to avoid collisions
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Validate cache key
     */
    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new \InvalidArgumentException('Cache key cannot be empty');
        }

        // PSR-16 specifies these characters are not allowed: {}()/\@
        if (preg_match('/[{}()\/@\\\]/', $key)) {
            throw new \InvalidArgumentException('Cache key contains invalid characters: ' . $key);
        }
    }

    /**
     * Calculate TTL in seconds
     */
    private function calculateTtl(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return 0; // No expiration
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTime();
            $expires = $now->add($ttl);
            return $expires->getTimestamp() - time();
        }

        return $ttl;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $value = \apcu_fetch($this->prefixKey($key), $success);
        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $ttlSeconds = $this->calculateTtl($ttl);
        return \apcu_store($this->prefixKey($key), $value, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return \apcu_delete($this->prefixKey($key));
    }

    public function clear(): bool
    {
        // APCu doesn't support clearing by prefix, so we clear everything
        // This is a limitation but acceptable for development
        return \apcu_clear_cache();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return \apcu_exists($this->prefixKey($key));
    }
}
