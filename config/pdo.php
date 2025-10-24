<?php

/**
 * Fallback PDO configuration for Mini framework
 *
 * This file is used when the application doesn't provide its own config/pdo.php.
 * It uses the already-loaded config from $GLOBALS['app']['config'] that was
 * loaded by mini\bootstrap().
 */

// Use the already-loaded config from bootstrap
$config = $GLOBALS['app']['config'] ?? [];

if (empty($config)) {
    throw new \Exception("Configuration not loaded. Make sure mini\\bootstrap() was called first.");
}

// Check for pdo_factory function first (preferred approach)
if (isset($config['pdo_factory']) && is_callable($config['pdo_factory'])) {
    return $config['pdo_factory']();
}

// Check for database configuration array
if (isset($config['database'])) {
    $dbConfig = $config['database'];

    $dsn = $dbConfig['dsn'] ?? null;
    $username = $dbConfig['username'] ?? null;
    $password = $dbConfig['password'] ?? null;
    $options = $dbConfig['options'] ?? [];

    if (!$dsn) {
        throw new \Exception("Database DSN not configured in config['database']['dsn']");
    }

    return new \PDO($dsn, $username, $password, $options);
}

// Fallback to SQLite for backward compatibility (temporary)
if (isset($config['dbfile'])) {
    $dbfile = $config['dbfile'];

    // Create directory if it doesn't exist
    $dbdir = dirname($dbfile);
    if (!is_dir($dbdir)) {
        mkdir($dbdir, 0755, true);
    }

    return new \PDO('sqlite:' . $dbfile);
}

// Default: SQLite database in project root
$defaultDbPath = $projectRoot . '/database.sqlite3';

// Create directory if it doesn't exist
$dbdir = dirname($defaultDbPath);
if (!is_dir($dbdir)) {
    mkdir($dbdir, 0755, true);
}

return new \PDO('sqlite:' . $defaultDbPath);