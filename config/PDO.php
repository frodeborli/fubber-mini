<?php
/**
 * Default PDO configuration for Mini framework
 *
 * Auto-creates SQLite database at ROOT/_database.sqlite3 with security checks.
 * Framework automatically configures PDO with UTF-8, timezone, exceptions, and assoc fetch.
 *
 * Applications can override by creating _config/PDO.php
 */

use mini\Database\PDOService;

$pdo = PDOService::createDefaultSqlite();
PDOService::configure($pdo);

return $pdo;
