<?php

namespace mini\Cache;

use mini\Database\DatabaseInterface;
use mini\Mini;
use Psr\SimpleCache\CacheInterface;

/**
 * Database-backed PSR-16 SimpleCache implementation
 *
 * Stores cache data in database with automatic garbage collection.
 * Uses the 'mini_cache' table for storage.
 *
 * IMPORTANT: Fetches DatabaseInterface from container on each access to ensure
 * proper scoping in long-running applications (cache is Singleton, db is Scoped).
 */
class DatabaseCache implements CacheInterface
{
    private string $tableName = 'mini_cache';

    public function __construct()
    {
        $this->ensureTableExists();
        $this->maybeGarbageCollect();
    }

    /**
     * Get DatabaseInterface from container (fresh per request)
     */
    private function db(): DatabaseInterface
    {
        return Mini::$mini->get(DatabaseInterface::class);
    }

    /**
     * Ensure the cache table exists
     */
    private function ensureTableExists(): void
    {
        $this->db()->exec("
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER,
                created_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ");

        // Create index for efficient garbage collection
        $this->db()->exec("
            CREATE INDEX IF NOT EXISTS idx_mini_cache_expires_at
            ON {$this->tableName} (expires_at)
        ");
    }

    /**
     * Randomly trigger garbage collection (1 in 10,000 chance)
     */
    private function maybeGarbageCollect(): void
    {
        if (mt_rand(0, 10000) === 0) {
            $this->garbageCollect();
        }
    }

    /**
     * Remove expired cache entries
     */
    private function garbageCollect(): void
    {
        $now = time();
        $this->db()->exec(
            "DELETE FROM {$this->tableName} WHERE expires_at IS NOT NULL AND expires_at < ?",
            [$now]
        );
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
        // Note: colon (:) is allowed in PSR-16
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

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $row = $this->db()->queryOne(
            "SELECT value, expires_at FROM {$this->tableName} WHERE key = ?",
            [$key]
        );

        if (!$row) {
            return $default;
        }

        // Check if expired
        if ($row->expires_at !== null && $row->expires_at < time()) {
            $this->delete($key);
            return $default;
        }

        return unserialize($row->value);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $serializedValue = serialize($value);
        $expiresAt = $this->calculateExpiration($ttl);

        try {
            $this->db()->exec(
                "INSERT OR REPLACE INTO {$this->tableName} (key, value, expires_at, created_at)
                 VALUES (?, ?, ?, ?)",
                [$key, $serializedValue, $expiresAt, time()]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        try {
            $this->db()->exec("DELETE FROM {$this->tableName} WHERE key = ?", [$key]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->db()->exec("DELETE FROM {$this->tableName}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
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

        $row = $this->db()->queryOne(
            "SELECT expires_at FROM {$this->tableName} WHERE key = ?",
            [$key]
        );

        if (!$row) {
            return false;
        }

        // Check if expired
        if ($row->expires_at !== null && $row->expires_at < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * Get cache statistics for debugging
     */
    public function getStats(): array
    {
        $total = $this->db()->queryField("SELECT COUNT(*) FROM {$this->tableName}");
        $expired = $this->db()->queryField(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE expires_at IS NOT NULL AND expires_at < ?",
            [time()]
        );

        return [
            'total_entries' => $total,
            'expired_entries' => $expired,
            'active_entries' => $total - $expired,
        ];
    }

    /**
     * Manually trigger garbage collection
     */
    public function cleanup(): int
    {
        $before = $this->db()->queryField("SELECT COUNT(*) FROM {$this->tableName}");
        $this->garbageCollect();
        $after = $this->db()->queryField("SELECT COUNT(*) FROM {$this->tableName}");

        return $before - $after;
    }
}
