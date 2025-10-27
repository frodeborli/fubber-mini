<?php

namespace mini\Logger;

use mini\Mini;

/**
 * Logger Service Factory
 *
 * Provides PSR-3 LoggerInterface instances with sensible defaults.
 * Default implementation logs to PHP's error_log with MessageFormatter interpolation.
 *
 * Applications can override by creating _config/Psr/Log/LoggerInterface.php
 */
class LoggerService
{
    /**
     * Create Logger instance
     *
     * Loads from config with fallback to framework's error_log logger.
     *
     * Config file: _config/Psr/Log/LoggerInterface.php
     */
    public static function factory(): \Psr\Log\LoggerInterface
    {
        // Try to load from application config
        $logger = Mini::$mini->loadServiceConfig(\Psr\Log\LoggerInterface::class, null);

        if ($logger !== null) {
            if (!($logger instanceof \Psr\Log\LoggerInterface)) {
                throw new \RuntimeException('_config/Psr/Log/LoggerInterface.php must return a PSR-3 LoggerInterface instance');
            }
            return $logger;
        }

        // Use framework's default error_log logger
        return self::createDefaultLogger();
    }

    /**
     * Create default error_log logger
     *
     * Returns Logger instance that logs to PHP's error_log.
     * Uses MessageFormatter for interpolation.
     */
    public static function createDefaultLogger(): \Psr\Log\LoggerInterface
    {
        return new Logger();
    }
}
