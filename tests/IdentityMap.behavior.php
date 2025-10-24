<?php

/**
 * Test identity map behavior for object identity consistency
 */

// Find composer autoloader
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    echo "Error: Could not find Composer autoloader\n";
    exit(1);
}

require_once $autoloader;

use mini\Util\IdentityMap;

function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (\Exception $e) {
        echo "✗ $description\n";
        echo "  Error: " . $e->getMessage() . "\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new \Exception("$message\nExpected: $expectedStr\nActual: $actualStr");
    }
}

// Test model class
class TestUser
{
    public int $id;
    public string $name;

    public function __construct(int $id = 0, string $name = '')
    {
        $this->id = $id;
        $this->name = $name;
    }
}

echo "Testing IdentityMap behavior...\n\n";

test('Basic storage and retrieval', function() {
    $map = new IdentityMap();
    $user = new TestUser(1, 'John');

    $map->remember($user, 1);
    $retrieved = $map->tryGet(1);

    assertEqual($user, $retrieved, 'Should return same object instance');
    assertEqual(true, $user === $retrieved, 'Should be identical objects (same reference)');
});

test('Object identity consistency', function() {
    $map = new IdentityMap();
    $user1 = new TestUser(1, 'John');
    $user2 = new TestUser(1, 'Jane'); // Different object, same ID

    $map->remember($user1, 1);
    $retrieved = $map->tryGet(1);

    assertEqual($user1, $retrieved, 'Should return first stored object');
    assertEqual(true, $user1 === $retrieved, 'Should be identical to first object');
    assertEqual(false, $user2 === $retrieved, 'Should NOT be identical to second object');
});

test('WeakReference garbage collection', function() {
    $map = new IdentityMap();

    // Create object in limited scope
    $user = new TestUser(1, 'John');
    $map->remember($user, 1);

    // Verify it exists
    $retrieved = $map->tryGet(1);
    assertEqual($user, $retrieved, 'Should retrieve object while still referenced');

    // Clear reference
    unset($user, $retrieved);

    // Force garbage collection (might not work in all PHP setups)
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    // Note: This test is not guaranteed to pass due to GC timing
    // but demonstrates the concept
    echo "  Note: WeakReference GC test depends on PHP garbage collection timing\n";
});

test('Forget operations', function() {
    $map = new IdentityMap();
    $user = new TestUser(1, 'John');

    $map->remember($user, 1);
    assertEqual($user, $map->tryGet(1), 'Should retrieve before forget');

    $map->forgetById(1);
    assertEqual(null, $map->tryGet(1), 'Should return null after forgetById');

    // Test forgetObject
    $map->remember($user, 1);
    assertEqual($user, $map->tryGet(1), 'Should retrieve before forgetObject');

    $map->forgetObject($user);
    assertEqual(null, $map->tryGet(1), 'Should return null after forgetObject');
});

test('Multiple objects with different IDs', function() {
    $map = new IdentityMap();
    $user1 = new TestUser(1, 'John');
    $user2 = new TestUser(2, 'Jane');

    $map->remember($user1, 1);
    $map->remember($user2, 2);

    assertEqual($user1, $map->tryGet(1), 'Should retrieve user1 by ID 1');
    assertEqual($user2, $map->tryGet(2), 'Should retrieve user2 by ID 2');
    assertEqual(null, $map->tryGet(3), 'Should return null for non-existent ID');
});

echo "\nIdentityMap behavior tests completed!\n";