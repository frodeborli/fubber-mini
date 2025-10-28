<?php

namespace mini;

use mini\Mini;
use mini\Logger\LoggerService;
use Psr\Log\LoggerInterface;

/**
 * Logger Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Logger feature.
 */

// Register Logger service when this file is loaded (after bootstrap.php)
// Only register if not already registered (allows app to override)
if (!Mini::$mini->has(LoggerInterface::class)) {
    Mini::$mini->addService(LoggerInterface::class, Lifetime::Singleton, fn() => LoggerService::factory());
}

/**
 * Get the application logger instance
 *
 * Returns a PSR-3 compatible logger. By default, uses the built-in Logger
 * that writes to PHP's error_log with MessageFormatter interpolation.
 * Can be overridden via _config/Psr/Log/LoggerInterface.php.
 *
 * @return LoggerInterface PSR-3 logger instance (singleton)
 */
function log(): LoggerInterface
{
    return Mini::$mini->get(LoggerInterface::class);
}
