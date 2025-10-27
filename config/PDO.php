<?php
/**
 * Default PDO configuration for Mini framework
 *
 * This file is used as a fallback if the application doesn't provide
 * its own _config/PDO.php file.
 *
 * Config file naming: Class name with namespace separators replaced by slashes.
 * \PDO::class → _config/PDO.php
 *
 * Auto-creates SQLite database at ROOT/_database.sqlite3 with security checks.
 *
 * Applications can override by creating _config/PDO.php and returning their own
 * PDO instance. The framework will automatically configure it with:
 * - UTF-8 encoding (based on PHP's default_charset)
 * - Timezone (from Mini configuration)
 * - Exception error mode
 * - Associative array fetch mode
 *
 * Example _config/PDO.php:
 *
 *   return new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
 *
 * Or use the framework's default SQLite creator:
 *
 *   return mini\Services\PDO::createDefaultSqlite();
 */

return mini\Services\PDO::createDefaultSqlite();
