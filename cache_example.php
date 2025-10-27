<?php
/**
 * Example usage of mini\cache() system
 *
 * This demonstrates the PSR-16 SimpleCache implementation with
 * smart driver selection (APCu > SQLite > Filesystem), namespacing,
 * and container integration.
 */

require_once 'vendor/autoload.php';

use function mini\{bootstrap, cache};

bootstrap();

echo "=== Mini Framework Cache System Demo ===\n\n";

// Basic cache usage
echo "1. Basic Cache Operations:\n";
$cache = cache();

// Store some data with 60 second TTL
$cache->set('user:123', ['name' => 'John Doe', 'email' => 'john@example.com'], 60);
$cache->set('config:theme', 'dark_mode');

// Retrieve data
$user = $cache->get('user:123');
$theme = $cache->get('config:theme');
$missing = $cache->get('nonexistent', 'default_value');

echo "User data: " . json_encode($user) . "\n";
echo "Theme: $theme\n";
echo "Missing key (with default): $missing\n\n";

// Namespaced cache usage
echo "2. Namespaced Cache:\n";
$userCache = cache('users');
$settingsCache = cache('settings');

// These keys won't conflict even though they're the same
$userCache->set('123', ['name' => 'Alice', 'role' => 'admin']);
$settingsCache->set('123', ['dark_mode' => true, 'language' => 'en']);

echo "User 123: " . json_encode($userCache->get('123')) . "\n";
echo "Settings 123: " . json_encode($settingsCache->get('123')) . "\n\n";

// Multiple operations
echo "3. Multiple Operations:\n";
$data = [
    'key1' => 'value1',
    'key2' => ['complex' => 'data'],
    'key3' => 12345
];

$cache->setMultiple($data, 30); // 30 second TTL
$retrieved = $cache->getMultiple(['key1', 'key2', 'key3', 'missing']);

foreach ($retrieved as $key => $value) {
    echo "$key: " . json_encode($value) . "\n";
}

echo "\n4. Cache Statistics:\n";
if (method_exists($cache, 'getStats')) {
    $stats = $cache->getStats();
    echo "Total entries: {$stats['total_entries']}\n";
    echo "Active entries: {$stats['active_entries']}\n";
    echo "Expired entries: {$stats['expired_entries']}\n";
}

echo "\n5. Cleanup Test:\n";
if (method_exists($cache, 'cleanup')) {
    $removed = $cache->cleanup();
    echo "Removed $removed expired entries\n";
}

// Show which driver is being used
$driverInfo = mini\Services\SimpleCache::getDriverInfo();
echo "\nCache system ready for use!\n";
echo "- Using driver: {$driverInfo['driver']} ({$driverInfo['class']})\n";
echo "- Smart fallback: APCu > SQLite in /tmp > Filesystem in /tmp\n";
echo "- Use cache() for global cache\n";
echo "- Use cache('namespace') for isolated cache sections\n";
echo "- Configurable via _config/Psr/SimpleCache/CacheInterface.php\n";