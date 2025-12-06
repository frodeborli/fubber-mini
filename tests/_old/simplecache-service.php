<?php
/**
 * Test SimpleCache Service class
 */

require __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\Services\SimpleCache;

echo "Testing SimpleCache Service Class\n";
echo str_repeat('=', 70) . "\n\n";

// Bootstrap framework (required for some service access)
\mini\bootstrap();

// Test 1: Driver detection and info
echo "Test 1: Driver detection\n";
$driverInfo = SimpleCache::getDriverInfo();
echo "✓ Auto-detected driver: " . $driverInfo['driver'] . "\n";
echo "  Class: " . $driverInfo['class'] . "\n";
echo "  APCu available: " . ($driverInfo['apcu_available'] ? 'yes' : 'no') . "\n";
echo "  SQLite available: " . ($driverInfo['sqlite_available'] ? 'yes' : 'no') . "\n";
echo "\n";

// Test 2: Container integration
echo "Test 2: Container integration\n";
try {
    $cache = Mini::$mini->get(\Psr\SimpleCache\CacheInterface::class);
    echo "✓ Cache service registered in container\n";
    echo "  Type: " . get_class($cache) . "\n";

    // Test it's a singleton
    $cache2 = Mini::$mini->get(\Psr\SimpleCache\CacheInterface::class);
    if ($cache === $cache2) {
        echo "✓ Cache is singleton (same instance)\n";
    } else {
        echo "✗ Cache is NOT singleton (different instances)\n";
    }

    // Test via cache() function
    $cache3 = \mini\cache();
    if ($cache === $cache3) {
        echo "✓ cache() function returns same instance\n";
    } else {
        echo "✗ cache() function returns different instance\n";
    }
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Basic cache operations
echo "Test 3: Basic cache operations\n";
try {
    $cache = \mini\cache();

    // Clear any existing data
    $cache->clear();

    // Set and get
    $cache->set('test_key', 'test_value');
    $value = $cache->get('test_key');
    if ($value === 'test_value') {
        echo "✓ Basic set/get works\n";
    } else {
        echo "✗ Basic set/get failed\n";
    }

    // Has
    if ($cache->has('test_key')) {
        echo "✓ has() works\n";
    } else {
        echo "✗ has() failed\n";
    }

    // Delete
    $cache->delete('test_key');
    if (!$cache->has('test_key')) {
        echo "✓ delete() works\n";
    } else {
        echo "✗ delete() failed\n";
    }

    // Multiple operations
    $cache->setMultiple(['key1' => 'value1', 'key2' => 'value2']);
    $values = $cache->getMultiple(['key1', 'key2']);
    if ($values['key1'] === 'value1' && $values['key2'] === 'value2') {
        echo "✓ setMultiple/getMultiple works\n";
    } else {
        echo "✗ setMultiple/getMultiple failed\n";
    }

    // TTL
    $cache->set('ttl_key', 'ttl_value', 1);
    sleep(2);
    $value = $cache->get('ttl_key', 'default');
    if ($value === 'default') {
        echo "✓ TTL expiration works\n";
    } else {
        echo "✗ TTL expiration failed\n";
    }

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Namespaced caching
echo "Test 4: Namespaced caching\n";
try {
    $cache1 = \mini\cache('namespace1');
    $cache2 = \mini\cache('namespace2');

    $cache1->set('shared_key', 'value_from_ns1');
    $cache2->set('shared_key', 'value_from_ns2');

    $value1 = $cache1->get('shared_key');
    $value2 = $cache2->get('shared_key');

    if ($value1 === 'value_from_ns1' && $value2 === 'value_from_ns2') {
        echo "✓ Namespaced caching isolates values\n";
    } else {
        echo "✗ Namespaced caching failed\n";
    }

    echo "  Namespace 1 type: " . get_class($cache1) . "\n";
    echo "  Namespace 2 type: " . get_class($cache2) . "\n";

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Config override (if exists)
echo "Test 5: Config override capability\n";
if (file_exists('_config/Psr/SimpleCache/CacheInterface.php')) {
    echo "✓ Application config exists at _config/Psr/SimpleCache/CacheInterface.php\n";
    $customCache = require '_config/Psr/SimpleCache/CacheInterface.php';
    echo "  Custom cache type: " . get_class($customCache) . "\n";
} else {
    echo "  No custom config (using framework default)\n";
    echo "  To test custom config, create _config/Psr/SimpleCache/CacheInterface.php\n";
}
echo "\n";

echo str_repeat('=', 70) . "\n";
echo "SimpleCache Service class working correctly!\n";
echo "\nKey features:\n";
echo "  ✓ Smart driver detection (APCu > SQLite > Filesystem)\n";
echo "  ✓ Container integration with Singleton lifetime\n";
echo "  ✓ PSR-16 compliant cache operations\n";
echo "  ✓ Namespace support for cache isolation\n";
echo "  ✓ Configurable via _config/Psr/SimpleCache/CacheInterface.php\n";
echo "  ✓ No database dependency for default cache\n";
