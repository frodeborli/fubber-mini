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

        $sqlTimezone = Mini::$mini->sqlTimezone;

        // Configure charset (UTF-8) and timezone based on driver
        switch ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $pdo->exec("SET NAMES utf8mb4, time_zone = '{$sqlTimezone}'");
                break;
            case 'pgsql':
                $pdo->exec("SET client_encoding TO 'UTF8', timezone TO '{$sqlTimezone}'");
                break;
            case 'oci':
                $pdo->exec("ALTER SESSION SET TIME_ZONE = '{$sqlTimezone}'");
                break;
            case 'sqlite':
                $pdo->exec("PRAGMA encoding = 'UTF-8'");
                // SQLite has no timezone concept - stores raw values
                break;
            case 'sqlsrv':
            case 'dblib':
                // SQL Server has no session timezone - uses server OS timezone.
                // Verify the server timezone matches sqlTimezone (cached briefly).
                // Short TTL (30s) because server timezone offset can change with DST transitions.
                $cacheKey = 'mini:sqlsrv_tz:' . md5(($pdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS) ?? 'default') . $sqlTimezone);
                $matches = apcu_fetch($cacheKey, $found);
                if (!$found) {
                    $serverOffset = (int)$pdo->query("SELECT DATEDIFF(MINUTE, GETUTCDATE(), GETDATE())")->fetchColumn();
                    $expectedOffset = self::parseTimezoneOffset($sqlTimezone);
                    $matches = ($serverOffset === $expectedOffset);
                    apcu_store($cacheKey, $matches, 30);
                }
                if (!$matches) {
                    throw new \RuntimeException(
                        "SQL Server timezone does not match configured sqlTimezone '{$sqlTimezone}'. " .
                        "SQL Server uses the OS timezone and cannot be changed per-connection. " .
                        "Either configure the server OS timezone or set SQL_TIMEZONE to match the server."
                    );
                }
                break;
        }
    }

    /**
     * Parse timezone offset string to minutes
     *
     * @param string $offset Offset like '+00:00', '-05:00', '+05:30'
     * @return int Offset in minutes from UTC
     */
    private static function parseTimezoneOffset(string $offset): int
    {
        if (!preg_match('/^([+-])(\d{2}):(\d{2})$/', $offset, $m)) {
            throw new \InvalidArgumentException(
                "Invalid sqlTimezone format '{$offset}'. Use offset format like '+00:00', '-05:00'."
            );
        }
        $minutes = ((int)$m[2] * 60) + (int)$m[3];
        return $m[1] === '-' ? -$minutes : $minutes;
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
