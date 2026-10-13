<?php

namespace mini\Contracts;

use Closure;

/**
 * What identifies "the current request" in this runtime.
 *
 * Scoped services and other per-request state are cached under the object `current()` returns,
 * so it must differ between requests that run at the same time, and between requests that run
 * one after the other in the same process:
 *
 * - Concurrent runtimes (Swerve, Swoole) already have one per request, such as the phasync
 *   context or the coroutine's context: `current()` returns it, and `run()` just calls `$fn`.
 * - Sequential workers (RoadRunner, FrankenPHP) have none: `run()` makes a new object for the
 *   duration of `$fn`, and `current()` returns it.
 *
 * Configure with `_config/mini/Contracts/RequestScopeInterface.php`, which returns an instance.
 */
interface RequestScopeInterface
{
    /**
     * The object identifying the request that is running now.
     *
     * @throws \LogicException when there is no request scope yet (before the Ready phase)
     */
    public function current(): object;

    /**
     * Run $fn as one request: the entry point of every request, called by the RequestDispatcher.
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    public function run(Closure $fn): mixed;
}
