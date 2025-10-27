<?php

namespace mini\Cache;

/**
 * SQLite-backed PSR-16 SimpleCache implementation for /tmp
 *
 * Stores cache data in SQLite database in temporary directory.
 * Lightweight alternative to DatabaseCache that doesn't require DatabaseInterface.
 */
class TmpSqliteCache implements \Psr\SimpleCache\CacheInterface
{
    private \PDO $pdo;
    private string $tableName = 'cache';

    public function __construct(?string $dbPath = null)
    {
        $dbPath = $dbPath ?? sys_get_temp_dir() . '/mini-cache.sqlite3';
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->ensureTableExists();
    }

    /**
     * Ensure the cache table exists
     */
    private function ensureTableExists(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER,
                created_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ");

        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_cache_expires_at
            ON {$this->tableName} (expires_at)
        ");
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

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $stmt = $this->pdo->prepare("SELECT value, expires_at FROM {$this->tableName} WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        // Check if expired
        if ($row['expires_at'] !== null && $row['expires_at'] < time()) {
            $this->delete($key);
            return $default;
        }

        return unserialize($row['value']);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $serializedValue = serialize($value);
        $expiresAt = $this->calculateExpiration($ttl);

        try {
            $stmt = $this->pdo->prepare(
                "INSERT OR REPLACE INTO {$this->tableName} (key, value, expires_at, created_at)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$key, $serializedValue, $expiresAt, time()]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE key = ?");
            $stmt->execute([$key]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->pdo->exec("DELETE FROM {$this->tableName}");
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

        $stmt = $this->pdo->prepare("SELECT expires_at FROM {$this->tableName} WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        // Check if expired
        if ($row['expires_at'] !== null && $row['expires_at'] < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * Manually trigger garbage collection (remove expired entries)
     */
    public function cleanup(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->tableName}");
        $stmt->execute();
        $before = $stmt->fetchColumn();

        $this->pdo->prepare(
            "DELETE FROM {$this->tableName} WHERE expires_at IS NOT NULL AND expires_at < ?"
        )->execute([time()]);

        $stmt->execute();
        $after = $stmt->fetchColumn();

        return $before - $after;
    }
}
