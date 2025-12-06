<?php
/**
 * Default PDO configuration for Mini framework
 *
 * Supports Laravel/Symfony DATABASE_URL format: driver://user:pass@host:port/dbname?options
 * Examples:
 *   mysql://root:secret@127.0.0.1:3306/myapp
 *   postgresql://user:pass@localhost/mydb
 *   sqlite:///path/to/database.sqlite
 *
 * Priority:
 * 1. MINI_DATABASE_URL - Mini-specific override (for coexisting with other frameworks)
 * 2. DATABASE_URL - Standard Laravel/Symfony format
 * 3. Auto-created SQLite database at ROOT/_database.sqlite3
 *
 * Framework automatically configures PDO with UTF-8, timezone, exceptions, and assoc fetch
 * via PDOService::configure() in the service registration.
 *
 * Applications can override by creating _config/PDO.php (only need to return PDO instance).
 */

use mini\Database\PDOService;

// Check for DATABASE_URL (Laravel/Symfony format)
$url = $_ENV['MINI_DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;

if ($url !== null) {
    $parsed = parse_url($url);

    if ($parsed === false || !isset($parsed['scheme'])) {
        throw new RuntimeException("Invalid DATABASE_URL format: $url");
    }

    $driver = $parsed['scheme'];
    $user = isset($parsed['user']) ? urldecode($parsed['user']) : null;
    $pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;
    $host = $parsed['host'] ?? '127.0.0.1';
    $port = $parsed['port'] ?? null;
    $dbname = ltrim($parsed['path'] ?? '', '/');
    $query = $parsed['query'] ?? '';

    // Build PDO DSN based on driver
    if ($driver === 'sqlite' || $driver === 'sqlite3') {
        // SQLite: path is the database file
        $dsn = "sqlite:$dbname";
    } elseif ($driver === 'mysql') {
        $dsn = "mysql:host=$host;dbname=$dbname";
        if ($port) $dsn .= ";port=$port";
        // Parse query string for additional options
        parse_str($query, $options);
        if (isset($options['charset'])) $dsn .= ";charset={$options['charset']}";
    } elseif ($driver === 'pgsql' || $driver === 'postgresql' || $driver === 'postgres') {
        $dsn = "pgsql:host=$host;dbname=$dbname";
        if ($port) $dsn .= ";port=$port";
    } else {
        // Generic fallback
        $dsn = "$driver:host=$host;dbname=$dbname";
        if ($port) $dsn .= ";port=$port";
    }

    return new PDO($dsn, $user, $pass);
}

// Fall back to auto-created SQLite
return PDOService::createDefaultSqlite();
