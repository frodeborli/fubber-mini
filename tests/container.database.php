<?php
/**
 * Test database service in container with smart defaults
 */

require __DIR__ . '/../vendor/autoload.php';

use mini\Mini;

echo "Testing Database Container Service\n";
echo str_repeat('=', 70) . "\n\n";

// Bootstrap framework (required for Scoped services)
\mini\bootstrap();

// Test 1: Auto-create SQLite database (no config)
echo "Test 1: Auto-create SQLite database\n";
try {
    $db = \mini\db();
    echo "✓ Database created successfully\n";
    echo "✓ Type: " . get_class($db) . "\n";

    // Verify it's SQLite and configured properly
    $pdo = Mini::$mini->get(\PDO::class);
    echo "✓ PDO driver: " . $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) . "\n";

    // Check PDO configuration
    $errorMode = $pdo->getAttribute(\PDO::ATTR_ERRMODE);
    $fetchMode = $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE);

    if ($errorMode === \PDO::ERRMODE_EXCEPTION) {
        echo "✓ PDO error mode: ERRMODE_EXCEPTION\n";
    } else {
        echo "✗ PDO error mode NOT set to ERRMODE_EXCEPTION\n";
    }

    if ($fetchMode === \PDO::FETCH_ASSOC) {
        echo "✓ PDO fetch mode: FETCH_ASSOC\n";
    } else {
        echo "✗ PDO fetch mode NOT set to FETCH_ASSOC\n";
    }

    // Verify database file was created
    $dbPath = Mini::$mini->root . '/_database.sqlite3';
    if (file_exists($dbPath)) {
        echo "✓ Database file created at: $dbPath\n";
    } else {
        echo "✗ Database file NOT found at: $dbPath\n";
    }

    // Test basic query
    $db->exec('CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY, name TEXT)');
    $db->exec('INSERT INTO test (name) VALUES (?)', ['Test Item']);
    $result = $db->query('SELECT * FROM test');
    echo "✓ Database operations work (inserted and queried " . count($result) . " rows)\n";

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Scoped behavior (same instance within request)
echo "Test 2: Scoped behavior\n";
try {
    $db1 = \mini\db();
    $db2 = \mini\db();
    $pdo1 = Mini::$mini->get(\PDO::class);
    $pdo2 = Mini::$mini->get(\PDO::class);

    if ($db1 === $db2) {
        echo "✓ DatabaseInterface is scoped (same instance within request)\n";
    } else {
        echo "✗ DatabaseInterface returns different instances\n";
    }

    if ($pdo1 === $pdo2) {
        echo "✓ PDO is scoped (same instance within request)\n";
    } else {
        echo "✗ PDO returns different instances\n";
    }
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Accessing via container
echo "Test 3: Container access\n";
try {
    $container = Mini::$mini;

    if ($container->has(\PDO::class)) {
        echo "✓ Container has PDO service\n";
    } else {
        echo "✗ Container does NOT have PDO service\n";
    }

    if ($container->has(\mini\Database\DatabaseInterface::class)) {
        echo "✓ Container has DatabaseInterface service\n";
    } else {
        echo "✗ Container does NOT have DatabaseInterface service\n";
    }

    $pdoFromContainer = $container->get(\PDO::class);
    $dbFromContainer = $container->get(\mini\Database\DatabaseInterface::class);
    $dbFromFunction = \mini\db();

    if ($dbFromContainer === $dbFromFunction) {
        echo "✓ Container and function return same instance\n";
    } else {
        echo "✗ Container and function return DIFFERENT instances\n";
    }
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "All tests completed!\n";
echo "\nNote: To test with custom PDO config, create _config/PDO.php\n";
echo "Example:\n";
echo "  <?php\n";
echo "  return new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');\n";
