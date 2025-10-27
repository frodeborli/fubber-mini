<?php
/**
 * Default SimpleCache configuration for Mini framework
 *
 * This file is used as a fallback if the application doesn't provide
 * its own _config/Psr/SimpleCache/CacheInterface.php file.
 *
 * Config file naming: Class name with namespace separators replaced by slashes.
 * \Psr\SimpleCache\CacheInterface::class → _config/Psr/SimpleCache/CacheInterface.php
 *
 * Auto-detects best available cache driver:
 * 1. APCu (if available) - fastest, in-memory
 * 2. SQLite in /tmp (if available) - fast, persistent
 * 3. Filesystem in /tmp - slowest, but always available
 *
 * Applications can override by creating _config/Psr/SimpleCache/CacheInterface.php
 * and returning their own PSR-16 CacheInterface instance.
 *
 * Example _config/Psr/SimpleCache/CacheInterface.php:
 *
 *   // Use Redis
 *   return new \Redis\Cache($redisClient);
 *
 *   // Use Memcached
 *   return new \Memcached\Cache($memcachedClient);
 *
 *   // Use database with app's DatabaseInterface
 *   return new mini\Cache\DatabaseCache(mini\Mini::$mini->get(mini\Contracts\DatabaseInterface::class));
 *
 *   // Or use framework's auto-detection
 *   return mini\Services\SimpleCache::createDefaultCache();
 */

return mini\Services\SimpleCache::createDefaultCache();
