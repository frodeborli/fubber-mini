<?php

namespace mini\Cache;

/**
 * Filesystem-backed PSR-16 SimpleCache implementation
 *
 * Stores cache data in serialized files with hashed filenames.
 * Uses sys_get_temp_dir() for storage location.
 */
class FilesystemCache implements \Psr\SimpleCache\CacheInterface
{
    private string $cacheDir;
    private string $prefix;

    public function __construct(?string $cacheDir = null, string $prefix = 'mini_cache_')
    {
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/mini-cache';
        $this->prefix = $prefix;
        $this->ensureCacheDirectory();
    }

    /**
     * Ensure cache directory exists
     */
    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get cache file path for key
     */
    private function getCacheFilePath(string $key): string
    {
        $hash = hash('sha256', $this->prefix . $key);
        return $this->cacheDir . '/' . $hash;
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
     * Calculate expiration timestamp from TTL
     */
    private function calculateExpiration(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null; // No expiration
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTime();
            $expires = $now->add($ttl);
            return $expires->getTimestamp();
        }

        if ($ttl <= 0) {
            return time() - 1; // Already expired
        }

        return time() + $ttl;
    }

    /**
     * Read cache entry from file
     */
    private function readCacheFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = @unserialize($contents);
        if (!is_array($data) || !isset($data['value'], $data['expires_at'])) {
            return null;
        }

        return $data;
    }

    /**
     * Write cache entry to file
     */
    private function writeCacheFile(string $path, mixed $value, ?int $expiresAt): bool
    {
        $data = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];

        $serialized = serialize($data);
        $result = @file_put_contents($path, $serialized, LOCK_EX);
        return $result !== false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $path = $this->getCacheFilePath($key);
        $data = $this->readCacheFile($path);

        if ($data === null) {
            return $default;
        }

        // Check if expired
        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            @unlink($path);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $path = $this->getCacheFilePath($key);
        $expiresAt = $this->calculateExpiration($ttl);

        return $this->writeCacheFile($path, $value, $expiresAt);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $path = $this->getCacheFilePath($key);

        if (!file_exists($path)) {
            return true; // Already deleted
        }

        return @unlink($path);
    }

    public function clear(): bool
    {
        if (!is_dir($this->cacheDir)) {
            return true;
        }

        $files = glob($this->cacheDir . '/*');
        if ($files === false) {
            return false;
        }

        $success = true;
        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $success = false;
            }
        }

        return $success;
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
        $path = $this->getCacheFilePath($key);
        $data = $this->readCacheFile($path);

        if ($data === null) {
            return false;
        }

        // Check if expired
        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            @unlink($path);
            return false;
        }

        return true;
    }

    /**
     * Manually trigger garbage collection (remove expired entries)
     */
    public function cleanup(): int
    {
        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $files = glob($this->cacheDir . '/*');
        if ($files === false) {
            return 0;
        }

        $removed = 0;
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $data = $this->readCacheFile($file);
            if ($data === null) {
                continue;
            }

            if ($data['expires_at'] !== null && $data['expires_at'] < $now) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
