<?php

namespace mini\Session;

use mini\Mini;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Cache-backed session implementation
 *
 * Stores session data in Mini's cache backend (APCu, SQLite, filesystem, etc.).
 * Uses a short in-memory TTL (100ms) for read operations to reduce cache lookups.
 * Write operations always fetch fresh data before updating to minimize conflicts.
 *
 * Usage:
 * ```php
 * // Just access $_SESSION - session auto-starts
 * $_SESSION['user_id'] = 123;        // Fetches fresh, updates, saves
 * $userId = $_SESSION['user_id'];    // Returns from memory if fresh
 * ```
 */
class Session implements SessionInterface
{
    /** In-memory session data cache */
    private ?array $sessionData = null;

    /** Timestamp when sessionData was last loaded */
    private ?float $sessionDataUpdated = null;

    /** Session ID */
    private ?string $id = null;

    /** Pending cookie data to set on response */
    private ?array $cookieToSet = null;

    /** How long to trust in-memory data for reads (seconds) */
    private const READ_CACHE_TTL = 0.1; // 100ms

    /** Cache key prefix */
    private const CACHE_PREFIX = 'session:';

    /**
     * Get session cache TTL in seconds
     *
     * Uses session_cache_expire() which returns minutes (default 180).
     * This controls how long session data is stored in cache.
     * Can be configured via php.ini session.cache_expire or by calling
     * session_cache_expire($minutes) during application bootstrap.
     */
    private function getCacheTtl(): int
    {
        return session_cache_expire() * 60;
    }

    /**
     * Get cookie options from PHP configuration with secure fallbacks
     *
     * Reads from session_get_cookie_params() but applies secure defaults
     * when php.ini values are empty/insecure.
     */
    private function getCookieOptions(): array
    {
        $params = session_get_cookie_params();

        // Cookie lifetime: 0 = session cookie, >0 = persistent cookie
        // If 0, we use cache TTL for persistence; if set, respect it
        $lifetime = $params['lifetime'];
        if ($lifetime > 0) {
            $expires = time() + $lifetime;
        } else {
            // Session cookie behavior: use cache TTL so cookie outlives typical browser session
            // but still expires eventually (matches server-side expiration)
            $expires = time() + $this->getCacheTtl();
        }

        return [
            'expires' => $expires,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?: null,
            'secure' => $params['secure'] ?: null, // null = auto-detect in middleware
            'httponly' => $params['httponly'] ?: true, // Secure default
            'samesite' => $params['samesite'] ?: 'Lax', // Secure default
        ];
    }

    /**
     * Check if strict mode is enabled
     *
     * When enabled, session IDs not found in cache are rejected and
     * a new session is created. This prevents session fixation attacks.
     */
    private function isStrictMode(): bool
    {
        return (bool) ini_get('session.use_strict_mode');
    }

    /**
     * Get the cache instance
     */
    private function getCache(): CacheInterface
    {
        return Mini::$mini->get(CacheInterface::class);
    }

    /**
     * Get cache key for this session
     */
    private function getCacheKey(): string
    {
        return self::CACHE_PREFIX . $this->id;
    }

    /**
     * Get session ID from request cookie, or generate new one
     *
     * If session.use_strict_mode is enabled, validates that the session
     * exists in cache before accepting it. Unknown session IDs are rejected
     * and a new session is created (prevents session fixation).
     */
    private function ensureId(): void
    {
        if ($this->id !== null) {
            return;
        }

        $sessionName = session_name() ?: 'PHPSESSID';
        $cookies = $this->getCookies();

        if (isset($cookies[$sessionName]) && $this->isValidSessionId($cookies[$sessionName])) {
            $candidateId = $cookies[$sessionName];

            // Strict mode: verify session exists in cache
            if ($this->isStrictMode()) {
                $cacheKey = self::CACHE_PREFIX . $candidateId;
                if ($this->getCache()->has($cacheKey)) {
                    $this->id = $candidateId;
                    return;
                }
                // Session not in cache - reject and create new (session fixation protection)
            } else {
                $this->id = $candidateId;
                return;
            }
        }

        // No valid session cookie or strict mode rejected it - create new session
        $this->id = $this->generateId();
        $this->setSessionCookie();
    }

    /**
     * Validate session ID format
     */
    private function isValidSessionId(string $id): bool
    {
        return preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $id) === 1;
    }

    /**
     * Generate a new session ID
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Mark that session cookie needs to be set on response
     */
    private function setSessionCookie(): void
    {
        $sessionName = session_name() ?: 'PHPSESSID';
        $options = $this->getCookieOptions();

        // Remove null values (domain, secure) - middleware handles them
        $options = array_filter($options, fn($v) => $v !== null);

        $this->cookieToSet = [
            'name' => $sessionName,
            'value' => $this->id,
            'options' => $options,
        ];
    }

    /**
     * Get cookies from current request
     */
    private function getCookies(): array
    {
        try {
            $request = Mini::$mini->get(ServerRequestInterface::class);
            return $request->getCookieParams();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Load session data from cache
     */
    private function loadFromCache(): void
    {
        $this->ensureId();
        $this->sessionData = $this->getCache()->get($this->getCacheKey()) ?? [];
        $this->sessionDataUpdated = microtime(true);
    }

    /**
     * Save session data to cache
     */
    private function saveToCache(): void
    {
        $this->getCache()->set($this->getCacheKey(), $this->sessionData, $this->getCacheTtl());
        $this->sessionDataUpdated = microtime(true);
    }

    /**
     * Ensure data is loaded for read operations
     * Uses in-memory cache if fresh (within READ_CACHE_TTL)
     */
    private function ensureLoadedForRead(): void
    {
        if ($this->sessionData !== null) {
            $age = microtime(true) - $this->sessionDataUpdated;
            if ($age < self::READ_CACHE_TTL) {
                return; // Use cached version
            }
        }

        $this->loadFromCache();
    }

    /**
     * Load fresh data, apply update, save immediately
     */
    private function updateAndSave(callable $updater): void
    {
        $this->loadFromCache();
        $updater();
        $this->saveToCache();
    }

    // =========================================================================
    // SessionInterface implementation
    // =========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoadedForRead();
        return $this->sessionData[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->updateAndSave(function () use ($key, $value) {
            $this->sessionData[$key] = $value;
        });
    }

    public function has(string $key): bool
    {
        $this->ensureLoadedForRead();
        return array_key_exists($key, $this->sessionData);
    }

    public function remove(string $key): void
    {
        $this->updateAndSave(function () use ($key) {
            unset($this->sessionData[$key]);
        });
    }

    public function all(): array
    {
        $this->ensureLoadedForRead();
        return $this->sessionData;
    }

    public function clear(): void
    {
        $this->updateAndSave(function () {
            $this->sessionData = [];
        });
    }

    public function getId(): string
    {
        $this->ensureId();
        return $this->id;
    }

    public function regenerate(bool $deleteOldSession = false): bool
    {
        $this->ensureLoadedForRead();
        $oldKey = $this->getCacheKey();
        $oldData = $this->sessionData;

        // Generate new ID
        $this->id = $this->generateId();
        $this->setSessionCookie();

        // Copy data to new session
        $this->sessionData = $oldData;
        $this->saveToCache();

        // Delete old session if requested
        if ($deleteOldSession) {
            $this->getCache()->delete($oldKey);
        }

        return true;
    }

    public function isStarted(): bool
    {
        return $this->sessionData !== null;
    }

    public function save(): void
    {
        if ($this->sessionData !== null) {
            $this->saveToCache();
        }
    }

    public function destroy(): bool
    {
        $this->ensureId();

        // Clear local data
        $this->sessionData = [];
        $this->sessionDataUpdated = null;

        // Delete from cache
        $this->getCache()->delete($this->getCacheKey());

        // Mark cookie for expiration (use same path as session cookie)
        $sessionName = session_name() ?: 'PHPSESSID';
        $params = session_get_cookie_params();
        $this->cookieToSet = [
            'name' => $sessionName,
            'value' => '',
            'options' => [
                'expires' => 1,
                'path' => $params['path'] ?: '/',
            ],
        ];

        return true;
    }

    public function getCookieToSet(): ?array
    {
        $cookie = $this->cookieToSet;
        $this->cookieToSet = null;
        return $cookie;
    }

    // =========================================================================
    // ArrayAccess implementation
    // =========================================================================

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove((string) $offset);
    }

    // =========================================================================
    // Countable implementation
    // =========================================================================

    public function count(): int
    {
        $this->ensureLoadedForRead();
        return count($this->sessionData);
    }

    // =========================================================================
    // IteratorAggregate implementation
    // =========================================================================

    public function getIterator(): \ArrayIterator
    {
        $this->ensureLoadedForRead();
        return new \ArrayIterator($this->sessionData);
    }

    // =========================================================================
    // Debug support
    // =========================================================================

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'dataLoaded' => $this->sessionData !== null,
            'dataAge' => $this->sessionDataUpdated ? (microtime(true) - $this->sessionDataUpdated) : null,
            'data' => $this->sessionData ?? '(not loaded)',
        ];
    }
}
