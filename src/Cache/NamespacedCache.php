<?php

namespace mini\Cache;

/**
 * Namespaced cache proxy
 *
 * Wraps another cache implementation and prefixes all keys with a namespace.
 * This allows logical separation of cache entries without separate cache instances.
 */
class NamespacedCache implements \Psr\SimpleCache\CacheInterface
{
    private \Psr\SimpleCache\CacheInterface $cache;
    private string $namespace;
    private string $separator;

    public function __construct(\Psr\SimpleCache\CacheInterface $cache, string $namespace, string $separator = ':')
    {
        $this->cache = $cache;
        $this->namespace = $namespace;
        $this->separator = $separator;
    }

    /**
     * Prefix key with namespace
     */
    private function prefixKey(string $key): string
    {
        return $this->namespace . $this->separator . $key;
    }

    /**
     * Prefix multiple keys with namespace
     */
    private function prefixKeys(iterable $keys): array
    {
        $prefixedKeys = [];
        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefixKey($key);
        }
        return $prefixedKeys;
    }

    /**
     * Remove namespace prefix from results
     */
    private function unprefixResults(iterable $results): array
    {
        $unprefixed = [];
        $prefixLength = strlen($this->namespace . $this->separator);

        foreach ($results as $key => $value) {
            $originalKey = substr($key, $prefixLength);
            $unprefixed[$originalKey] = $value;
        }

        return $unprefixed;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->prefixKey($key), $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->cache->set($this->prefixKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->prefixKey($key));
    }

    public function clear(): bool
    {
        // For namespaced cache, we can't clear everything as that would affect other namespaces
        // Instead, we would need to delete all keys with our prefix
        // This is a limitation of the simple approach, but keeps it lightweight

        // Note: This is not a full implementation as it would require scanning all keys
        // For a production system, you might want to track namespaced keys separately
        throw new \LogicException('Clear operation not supported on namespaced cache. Use the root cache instance to clear all entries.');
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $prefixedKeys = $this->prefixKeys($keys);
        $results = $this->cache->getMultiple($prefixedKeys, $default);

        // Map results back to original keys
        $mappedResults = [];
        $originalKeys = is_array($keys) ? $keys : iterator_to_array($keys);
        $prefixLength = strlen($this->namespace . $this->separator);

        foreach ($results as $prefixedKey => $value) {
            $originalKey = substr($prefixedKey, $prefixLength);
            $mappedResults[$originalKey] = $value;
        }

        return $mappedResults;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$this->prefixKey($key)] = $value;
        }

        return $this->cache->setMultiple($prefixedValues, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $prefixedKeys = $this->prefixKeys($keys);
        return $this->cache->deleteMultiple($prefixedKeys);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->prefixKey($key));
    }

    /**
     * Get the namespace for this cache instance
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get the underlying cache instance
     */
    public function getUnderlyingCache(): \Psr\SimpleCache\CacheInterface
    {
        return $this->cache;
    }
}