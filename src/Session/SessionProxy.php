<?php

namespace mini\Session;

use mini\Mini;

/**
 * Global proxy for $_SESSION that auto-starts sessions on access
 *
 * This proxy replaces PHP's native $_SESSION superglobal, providing:
 * - Automatic session start on first array access
 * - Per-request session instances in fiber/async environments
 * - Transparent integration with existing $_SESSION code
 *
 * Traditional PHP behavior:
 * ```php
 * session_start();                 // Must call manually
 * $_SESSION['user'] = 'john';      // Only works after session_start()
 * ```
 *
 * With SessionProxy:
 * ```php
 * $_SESSION['user'] = 'john';      // Auto-starts session, just works
 * $user = $_SESSION['user'];       // Returns 'john'
 * ```
 *
 * The proxy delegates all operations to a per-request SessionInterface
 * instance obtained from Mini's service container. This enables:
 * - Fiber-safe sessions (each request has its own Session instance)
 * - Testability (swap SessionInterface for a mock)
 * - Runtime agnostic (works in FPM, Swoole, phasync, etc.)
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class SessionProxy implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * Get the Session instance for the current request
     */
    private function getSession(): SessionInterface
    {
        return Mini::$mini->get(SessionInterface::class);
    }

    // =========================================================================
    // ArrayAccess implementation
    // =========================================================================

    /**
     * Check if a session key exists
     *
     * Auto-starts session on first access.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->getSession()->has((string) $offset);
    }

    /**
     * Get a session value
     *
     * Auto-starts session on first access.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getSession()->get((string) $offset);
    }

    /**
     * Set a session value
     *
     * Auto-starts session on first access.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->getSession()->set((string) $offset, $value);
    }

    /**
     * Remove a session key
     *
     * Auto-starts session on first access.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->getSession()->remove((string) $offset);
    }

    // =========================================================================
    // Countable implementation
    // =========================================================================

    /**
     * Count session items
     *
     * Auto-starts session on first access.
     */
    public function count(): int
    {
        return count($this->getSession());
    }

    // =========================================================================
    // IteratorAggregate implementation
    // =========================================================================

    /**
     * Get iterator for session data
     *
     * Auto-starts session on first access.
     *
     * @return \ArrayIterator<string, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return $this->getSession()->getIterator();
    }

    // =========================================================================
    // Debug support
    // =========================================================================

    /**
     * Debug info for var_dump()
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        try {
            $session = $this->getSession();
            return [
                'started' => $session->isStarted(),
                'id' => $session->isStarted() ? $session->getId() : '(not started)',
                'data' => $session->isStarted() ? $session->all() : '(not started)',
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'Session service not available: ' . $e->getMessage(),
            ];
        }
    }
}
