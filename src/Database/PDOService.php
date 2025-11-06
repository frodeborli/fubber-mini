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
     * This is always applied regardless of where the PDO instance came from.
     * Applications can create custom _config/pdo.php but will still get
     * proper charset, timezone, and error handling configuration.
     */
    public static function configure(\PDO $pdo): void
    {
        // Always throw exceptions on errors
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Always fetch as associative arrays
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Get character set from PHP ini (defaults to UTF-8)
        $charset = \ini_get('default_charset') ?: 'UTF-8';

        // Get driver name
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Configure charset and timezone based on driver
        switch ($driver) {
            case 'mysql':
                // MySQL: Set charset and timezone
                $mysqlCharset = strtolower($charset) === 'utf-8' ? 'utf8mb4' : $charset;
                $pdo->exec("SET NAMES {$mysqlCharset}");
                $pdo->exec("SET time_zone = '" . Mini::$mini->timezone . "'");
                break;

            case 'pgsql':
                // PostgreSQL: Set client encoding and timezone
                $pgsqlCharset = strtoupper($charset);
                $pdo->exec("SET client_encoding TO '{$pgsqlCharset}'");
                $pdo->exec("SET timezone TO '" . Mini::$mini->timezone . "'");
                break;

            case 'sqlite':
                // SQLite: Set encoding (UTF-8/UTF-16)
                if (stripos($charset, 'utf-8') !== false || stripos($charset, 'utf8') !== false) {
                    $pdo->exec("PRAGMA encoding = 'UTF-8'");
                }
                // Note: SQLite doesn't support timezone settings
                break;
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
