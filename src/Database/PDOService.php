<?php

namespace mini\Database;

use mini\Mini;
use mini\Exceptions\ConfigurationRequiredException;

/**
 * PDO Service Factory
 *
 * Provides configured PDO instances with smart defaults:
 * - UTF-8 encoding based on PHP's default_charset
 * - Timezone configuration from Mini
 * - Exception error mode
 * - Associative array fetch mode
 */
class PDOService
{
    /**
     * Create and configure PDO instance
     *
     * Loads PDO instance from config (with fallback to auto SQLite),
     * then applies standard configuration.
     *
     * Config file: _config/PDO.php
     */
    public static function factory(): \PDO
    {
        // Load PDO instance from config (application first, framework fallback)
        $pdo = Mini::$mini->loadServiceConfig(\PDO::class);

        if (!($pdo instanceof \PDO)) {
            throw new \RuntimeException('_config/PDO.php must return a PDO instance');
        }

        // Always apply standard configuration
        self::configure($pdo);

        return $pdo;
    }

    /**
     * Configure PDO instance with framework defaults
     *
     * Applies consistent configuration regardless of how PDO was instantiated:
     * - Exception error mode
     * - Associative array fetch mode
     * - UTF-8 charset
     * - UTC timezone
     */
    public static function configure(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Configure charset (UTF-8) and timezone (UTC) based on driver
        switch ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $pdo->exec("SET NAMES utf8mb4, time_zone = '+00:00'");
                break;
            case 'pgsql':
                $pdo->exec("SET client_encoding TO 'UTF8', timezone TO 'UTC'");
                break;
            case 'oci':
                $pdo->exec("ALTER SESSION SET TIME_ZONE = 'UTC'");
                break;
            case 'sqlite':
                $pdo->exec("PRAGMA encoding = 'UTF-8'");
                break;
            // sqlsrv/dblib: charset via connection string, no session timezone
        }
    }

    /**
     * Create default SQLite database (used by framework's config/pdo.php fallback)
     *
     * Auto-creates SQLite database at ROOT/_database.sqlite3 with security checks.
     * Applications can call this from their own config if they want the same behavior.
     */
    public static function createDefaultSqlite(): \PDO
    {
        // Check if SQLite extension is available
        if (!extension_loaded('pdo_sqlite')) {
            throw new ConfigurationRequiredException(
                'PDO.php',
                'database connection (SQLite extension not available for auto-configuration)'
            );
        }

        $dbPath = Mini::$mini->root . '/_database.sqlite3';

        // Security check: ensure database is NOT in document root
        if (Mini::$mini->docRoot !== null) {
            $realDbDir = realpath(dirname($dbPath)) ?: dirname($dbPath);
            $realDocRoot = realpath(Mini::$mini->docRoot);

            if (str_starts_with($realDbDir, $realDocRoot)) {
                throw new ConfigurationRequiredException(
                    'PDO.php',
                    'database connection (auto-created SQLite database would be web-accessible)'
                );
            }
        }

        // Create and return PDO instance (configuration will be applied by factory())
        return new \PDO('sqlite:' . $dbPath);
    }
}
