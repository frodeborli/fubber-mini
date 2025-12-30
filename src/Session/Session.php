<?php

namespace mini\Session;

use mini\Mini;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Native PHP session implementation
 *
 * Wraps PHP's native session handling with automatic start-on-access.
 * Works in traditional PHP-FPM/CGI environments.
 *
 * For fiber-based async environments (phasync, Swoole), this class uses
 * a load-into-memory pattern: session data is loaded once, kept in memory,
 * and saved at request end. This avoids holding session locks during
 * async operations.
 *
 * Usage:
 * ```php
 * // Automatic - just access $_SESSION
 * $_SESSION['user_id'] = 123;        // Auto-starts session
 * $userId = $_SESSION['user_id'];    // Returns 123
 *
 * // Or via the session() helper
 * session()->set('user_id', 123);
 * $userId = session()->get('user_id');
 * ```
 */
class Session implements SessionInterface
{
    /** @var array<string, mixed> In-memory session data */
    private array $data = [];

    /** @var bool Whether session has been started */
    private bool $started = false;

    /** @var bool Whether session data has been modified */
    private bool $modified = false;

    /** @var string|null Session ID */
    private ?string $id = null;

    /**
     * Create a new Session instance
     *
     * Session is not started until first access.
     */
    public function __construct()
    {
        // Session starts lazily on first access
    }

    /**
     * Ensure session is started before accessing data
     */
    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }

        $this->start();
    }

    /**
     * Start the session
     */
    private function start(): void
    {
        if ($this->started) {
            return;
        }

        // Get session ID from cookie if available
        $sessionName = session_name();
        $cookies = $this->getCookies();

        if (isset($cookies[$sessionName])) {
            session_id($cookies[$sessionName]);
        }

        // Configure session for CLI if needed
        if (PHP_SAPI === 'cli') {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
        }

        // Start native session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Load data into memory
        $this->data = $_SESSION ?? [];
        $this->id = session_id();
        $this->started = true;

        // For fiber environments: close session immediately to release lock
        // Data is kept in memory and saved at request end
        if ($this->isAsyncEnvironment()) {
            session_write_close();
        }
    }

    /**
     * Check if running in an async/fiber environment
     */
    private function isAsyncEnvironment(): bool
    {
        // Check if we're inside a Fiber (not the main fiber)
        $fiber = \Fiber::getCurrent();
        return $fiber !== null;
    }

    /**
     * Get cookies from current request
     *
     * @return array<string, string>
     */
    private function getCookies(): array
    {
        try {
            $request = Mini::$mini->get(ServerRequestInterface::class);
            return $request->getCookieParams();
        } catch (\Throwable) {
            // Fallback to native $_COOKIE during bootstrap
            return $_COOKIE ?? [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $this->data[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $this->data[$key] = $value;
        $this->modified = true;

        // In non-async mode, also update native $_SESSION
        if (!$this->isAsyncEnvironment() && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($key, $this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($this->data[$key]);
        $this->modified = true;

        // In non-async mode, also update native $_SESSION
        if (!$this->isAsyncEnvironment() && session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->ensureStarted();
        $this->data = [];
        $this->modified = true;

        // In non-async mode, also update native $_SESSION
        if (!$this->isAsyncEnvironment() && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        $this->ensureStarted();
        return $this->id ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function regenerate(bool $deleteOldSession = false): bool
    {
        $this->ensureStarted();

        if ($this->isAsyncEnvironment()) {
            // In async mode: reopen session, regenerate, close
            session_id($this->id);
            session_start();
            $result = session_regenerate_id($deleteOldSession);
            $this->id = session_id();
            $_SESSION = $this->data;
            session_write_close();
            return $result;
        }

        // In sync mode: use native regeneration
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $result = session_regenerate_id($deleteOldSession);
        $this->id = session_id();
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * {@inheritdoc}
     */
    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        if ($this->isAsyncEnvironment() && $this->modified) {
            // In async mode: reopen session, save data, close
            session_id($this->id);
            session_start();
            $_SESSION = $this->data;
            session_write_close();
            $this->modified = false;
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            // In sync mode: just close (data already in $_SESSION)
            session_write_close();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(): bool
    {
        if (!$this->started) {
            return false;
        }

        $this->data = [];
        $this->modified = false;

        if ($this->isAsyncEnvironment()) {
            // In async mode: reopen session, destroy, close
            session_id($this->id);
            session_start();
            $result = session_destroy();
            return $result;
        }

        // In sync mode: use native destroy
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_destroy();
        }

        return false;
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
        $this->ensureStarted();
        return count($this->data);
    }

    // =========================================================================
    // IteratorAggregate implementation
    // =========================================================================

    public function getIterator(): \ArrayIterator
    {
        $this->ensureStarted();
        return new \ArrayIterator($this->data);
    }

    // =========================================================================
    // Debug support
    // =========================================================================

    public function __debugInfo(): array
    {
        return [
            'started' => $this->started,
            'modified' => $this->modified,
            'id' => $this->id,
            'data' => $this->started ? $this->data : '(not started)',
        ];
    }
}
