<?php

namespace mini\Session;

/**
 * Session service interface
 *
 * Provides a per-request session abstraction that works transparently
 * in both traditional PHP-FPM and fiber-based async runtimes.
 *
 * The session auto-starts on first access and auto-saves at request end.
 * This eliminates the need to call session_start() manually.
 */
interface SessionInterface extends \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * Get a session value
     *
     * @param string $key
     * @param mixed $default Value to return if key doesn't exist
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a session value
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a session key exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a session key
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void;

    /**
     * Get all session data
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Clear all session data
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Get the session ID
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Regenerate the session ID
     *
     * @param bool $deleteOldSession Whether to delete the old session data
     * @return bool
     */
    public function regenerate(bool $deleteOldSession = false): bool;

    /**
     * Check if the session has been started
     *
     * @return bool
     */
    public function isStarted(): bool;

    /**
     * Explicitly save and close the session
     *
     * Normally called automatically at request end, but can be called
     * manually if you need to release the session lock early.
     *
     * @return void
     */
    public function save(): void;

    /**
     * Destroy the session completely
     *
     * Removes all session data and invalidates the session ID.
     *
     * @return bool
     */
    public function destroy(): bool;
}
