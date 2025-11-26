<?php
namespace mini\Async;

use Closure;
use Fiber;

/**
 * Event loop integration interface for async runtimes
 *
 * Async runtimes (phasync, Swoole, ReactPHP, etc.) implement this interface
 * to integrate with Mini's fiber-aware architecture. Mini does not provide
 * a default implementation - this is an integration point only.
 *
 * All methods are designed to "just work" regardless of whether an event
 * loop is already running. Implementations should handle bootstrapping
 * internally when needed.
 *
 * @package mini\Async
 */
interface AsyncInterface
{
    /**
     * Convenience method: spawn a fiber and wait for its result
     *
     * Equivalent to: await(go($fn, $args, $context))
     *
     * @param Closure $fn The function to run
     * @param array $args Arguments to pass to the function
     * @param object|null $context Optional context object for scoped services
     * @return mixed The function's return value
     */
    public function run(Closure $fn, array $args = [], ?object $context = null): mixed;

    /**
     * Spawn a new coroutine (fiber)
     *
     * Returns immediately with a Fiber handle. The fiber won't execute until
     * something drives the event loop (await, sleep, awaitStream, etc.)
     *
     * @param Closure $coroutine The function to run as a coroutine
     * @param array $args Arguments to pass to the coroutine
     * @param object|null $context Optional context object for scoped services
     * @return Fiber The created fiber
     */
    public function go(Closure $coroutine, array $args = [], ?object $context = null): Fiber;

    /**
     * Wait for a fiber to complete and return its result
     *
     * Always works - starts an event loop if none exists.
     *
     * @param Fiber $fiber The fiber to await
     * @return mixed The fiber's return value
     */
    public function await(Fiber $fiber): mixed;

    /**
     * Suspend execution for a duration
     *
     * Always works - uses usleep/stream_select if no event loop exists.
     *
     * @param float $seconds Time to sleep (0 = yield to other coroutines)
     */
    public function sleep(float $seconds = 0): void;

    /**
     * Suspend until a stream becomes ready for I/O
     *
     * Always works - uses stream_select() if no event loop exists.
     *
     * @param resource $resource The stream resource to wait on
     * @param int $mode Bitmask of READABLE, WRITABLE, EXCEPTION constants
     * @return resource The same resource (for chaining)
     */
    public function awaitStream($resource, int $mode): mixed;

    /**
     * Schedule a callback to run after current execution completes
     *
     * @param Closure $callback The callback to defer
     */
    public function defer(Closure $callback): void;

    /**
     * Handle an exception from async code
     *
     * @param \Throwable $exception The exception
     * @param Closure|null $source The closure that threw (if known)
     */
    public function handleException(\Throwable $exception, ?Closure $source = null): void;
}
