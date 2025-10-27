<?php

namespace mini\Services;

use mini\Mini;
use mini\Cache\ApcuCache;
use mini\Cache\TmpSqliteCache;
use mini\Cache\FilesystemCache;

/**
 * SimpleCache Service Factory
 *
 * Provides configured PSR-16 SimpleCache instances with smart defaults:
 * 1. APCu (if available) - fastest, in-memory
 * 2. SQLite in /tmp (if available) - fast, persistent
 * 3. Filesystem in /tmp - slowest, but always available
 *
 * Applications can override by creating _config/Psr/SimpleCache/CacheInterface.php
 */
class SimpleCache
{
    /**
     * Create SimpleCache instance
     *
     * Loads from config with fallback to auto-detected driver.
     *
     * Config file: _config/Psr/SimpleCache/CacheInterface.php
     */
    public static function factory(): \Psr\SimpleCache\CacheInterface
    {
        // Try to load from application config
        $cache = Mini::$mini->loadServiceConfig(\Psr\SimpleCache\CacheInterface::class, null);

        if ($cache !== null) {
            if (!($cache instanceof \Psr\SimpleCache\CacheInterface)) {
                throw new \RuntimeException('_config/Psr/SimpleCache/CacheInterface.php must return a PSR-16 CacheInterface instance');
            }
            return $cache;
        }

        // Auto-detect best available driver
        return self::createDefaultCache();
    }

    /**
     * Create default cache based on available extensions
     *
     * Priority: APCu > SQLite > Filesystem
     */
    public static function createDefaultCache(): \Psr\SimpleCache\CacheInterface
    {
        // 1. Try APCu (fastest)
        if (self::isApcuAvailable()) {
            return new ApcuCache();
        }

        // 2. Try SQLite in /tmp (fast and persistent)
        if (self::isSqliteAvailable()) {
            return new TmpSqliteCache();
        }

        // 3. Fallback to filesystem (always available)
        return new FilesystemCache();
    }

    /**
     * Check if APCu is available
     */
    public static function isApcuAvailable(): bool
    {
        return extension_loaded('apcu') && ini_get('apc.enabled');
    }

    /**
     * Check if SQLite is available
     */
    public static function isSqliteAvailable(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /**
     * Get information about which cache driver is being used
     *
     * Useful for debugging and optimization
     */
    public static function getDriverInfo(): array
    {
        $cache = self::createDefaultCache();
        $driver = match (true) {
            $cache instanceof ApcuCache => 'apcu',
            $cache instanceof TmpSqliteCache => 'sqlite',
            $cache instanceof FilesystemCache => 'filesystem',
            default => 'unknown',
        };

        return [
            'driver' => $driver,
            'class' => get_class($cache),
            'apcu_available' => self::isApcuAvailable(),
            'sqlite_available' => self::isSqliteAvailable(),
        ];
    }
}
