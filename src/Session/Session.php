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
 *
 * Writes are never silently dropped: if the cache backend refuses a write,
 * a RuntimeException is thrown rather than leaving the caller believing the
 * session was updated.
 */
class Session implements SessionInterface
{
    /** Cache backend, resolved from the container on first use when not injected */
    private ?CacheInterface $cache;

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

    /**
     * Cache key prefix
     *
     * PSR-16 reserves `{}()/\@:` in cache keys, so the separator is a dot.
     */
    private const CACHE_PREFIX = 'session.';

    /**
     * @param CacheInterface|null $cache Session store. Defaults to the
     *        container-provided cache; inject explicitly to isolate a session
     *        from the application cache (tests, dedicated session backend).
     */
    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

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
     *
     * Protected so a subclass can source the policy from somewhere other than
     * the process-global ini (`ini_set()` on session directives is refused once
     * output has started, and ini is process-wide in worker/fiber runtimes).
     */
    protected function isStrictMode(): bool
    {
        return (bool) ini_get('session.use_strict_mode');
    }

    /**
     * Get the cache instance
     */
    private function getCache(): CacheInterface
    {
        return $this->cache ??= Mini::$mini->get(CacheInterface::class);
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
     *
     * Protected so a subclass can supply cookies from another source
     * (tests, non-PSR-7 runtimes).
     */
    protected function getCookies(): array
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
     *
     * A refused write is fatal: the data the caller just wrote does not exist
     * anywhere, so continuing would leave a "logged in" user that is not logged
     * in. Fail loudly instead.
     *
     * The in-memory copy is dropped before throwing. It describes a state that
     * was never stored, and `ensureLoadedForRead()` trusts it for
     * READ_CACHE_TTL — without this, a caller that catches the exception (or
     * any later code in the same request) would read back the phantom write and
     * could authorize on it. Discarding it forces the next read to reload from
     * cache, which is the only state that actually exists.
     *
     * @throws \RuntimeException If the cache backend refuses the write
     */
    private function saveToCache(): void
    {
        if ($this->getCache()->set($this->getCacheKey(), $this->sessionData, $this->getCacheTtl()) !== true) {
            $this->sessionData = null;
            $this->sessionDataUpdated = null;
            throw new \RuntimeException(
                'Session write failed: ' . get_class($this->getCache()) . '::set() refused key "'
                . $this->getCacheKey() . '". Session data would have been lost silently.'
            );
        }
        $this->sessionDataUpdated = microtime(true);
    }

    /**
     * Delete a session from cache
     *
     * PSR-16 does not distinguish "nothing to delete" from "delete refused":
     * `apcu_delete()` — and therefore `mini\Cache\ApcuCache::delete()`, the
     * driver Mini prefers by default — returns `false` for a key that is simply
     * absent. Destroying a session that was never persisted, destroying twice
     * (double logout), or regenerating an unwritten session are all normal, so
     * a falsy return alone must not fail the request.
     *
     * The condition that actually matters is whether the data survived. Only
     * then is the session still readable and the failure real.
     *
     * @throws \RuntimeException If the session data is still present after the delete
     */
    private function deleteFromCache(string $cacheKey): void
    {
        if ($this->getCache()->delete($cacheKey) !== true && $this->getCache()->has($cacheKey)) {
            throw new \RuntimeException(
                'Session delete failed: ' . get_class($this->getCache()) . '::delete() refused key "'
                . $cacheKey . '". The session data is still readable.'
            );
        }
    }

    /**
     * Ensure data is loaded for read operations
     *
     * Uses the in-memory copy only while it is both present and stamped fresh
     * (within READ_CACHE_TTL). A missing stamp means the copy is not known to
     * match the store — `destroy()` clears it deliberately — so it is reloaded
     * rather than trusted.
     */
    private function ensureLoadedForRead(): void
    {
        if ($this->sessionData !== null && $this->sessionDataUpdated !== null) {
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
            $this->deleteFromCache($oldKey);
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
        $this->deleteFromCache($this->getCacheKey());

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
