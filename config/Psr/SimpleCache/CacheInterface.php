<?php
/**
 * Default SimpleCache configuration for Mini framework
 *
 * Auto-detects best available cache driver:
 * 1. APCu (if available) - fastest, in-memory
 * 2. SQLite in /tmp (if available) - fast, persistent
 * 3. Filesystem in /tmp - slowest, but always available
 *
 * Applications can override by creating _config/Psr/SimpleCache/CacheInterface.php
 */

use mini\Cache\ApcuCache;
use mini\Cache\TmpSqliteCache;
use mini\Cache\FilesystemCache;

// 1. Try APCu (fastest)
if (extension_loaded('apcu') && ini_get('apc.enabled')) {
    return new ApcuCache();
}

// 2. Try SQLite in /tmp (fast and persistent)
if (extension_loaded('pdo_sqlite')) {
    return new TmpSqliteCache();
}

// 3. Fallback to filesystem (always available)
return new FilesystemCache();
