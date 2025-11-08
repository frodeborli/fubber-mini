<?php

namespace mini;

use mini\Mini;
use Psr\Log\LoggerInterface;

/**
 * Logger Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Logger feature.
 */

// Register Logger service
Mini::$mini->addService(LoggerInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(LoggerInterface::class));

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
