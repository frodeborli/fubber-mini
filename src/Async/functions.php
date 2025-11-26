<?php
namespace mini;

use mini\Async\AsyncInterface;

/**
 * Async Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Async feature.
 */

// Register AsyncInterface service
Mini::$mini->addService(AsyncInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(AsyncInterface::class));

/**
 * I/O wait mode: wait until stream is readable
 */
const READABLE = 1;

/**
 * I/O wait mode: wait until stream is writable
 */
const WRITABLE = 2;

/**
 * I/O wait mode: wait until stream has exception/OOB data
 */
const EXCEPTION = 4;

/**
 * Get the configured async runtime
 *
 * Returns the registered AsyncInterface implementation. Async runtimes
 * (phasync, Swoole, ReactPHP) provide implementations via config file:
 *
 *   _config/mini/Async/AsyncInterface.php
 *
 * The config file should return an AsyncInterface instance.
 *
 * @return AsyncInterface
 * @throws \LogicException If no async runtime is configured
 */
function async(): AsyncInterface
{
    return Mini::$mini->get(AsyncInterface::class);
}
