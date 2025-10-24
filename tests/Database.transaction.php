<?php

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

// Simple test helpers
function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (Exception $e) {
        fwrite(STDERR, "✗ $description\n");
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new Exception("$message\nExpected: $expectedStr\nActual: $actualStr");
    }
}

use mini\Database\PdoDatabase;

// Create test database
$pdo = new PDO('sqlite::memory:');
$db = new PdoDatabase($pdo);

$db->exec("CREATE TABLE test_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100),
    count INTEGER DEFAULT 0
)");

// Test basic transaction
test('Basic transaction commits successfully', function() use ($db) {
    $result = $db->transaction(function() use ($db) {
        $db->exec("INSERT INTO test_users (name, count) VALUES (?, ?)", ['Alice', 1]);
        return 'success';
    });

    assertEqual('success', $result);

    // Verify data was committed
    $user = $db->queryOne("SELECT * FROM test_users WHERE name = ?", ['Alice']);
    assertEqual('Alice', $user['name']);
    assertEqual(1, (int)$user['count']);
});

// Test transaction rollback on exception
test('Transaction rolls back on exception', function() use ($db) {
    try {
        $db->transaction(function() use ($db) {
            $db->exec("INSERT INTO test_users (name, count) VALUES (?, ?)", ['Bob', 2]);
            throw new Exception("Something went wrong");
        });
    } catch (Exception $e) {
        // Exception expected
    }

    // Verify data was rolled back
    $user = $db->queryOne("SELECT * FROM test_users WHERE name = ?", ['Bob']);
    assertEqual(null, $user);
});

// Test nested transactions
test('Nested transactions work correctly', function() use ($db) {
    $result = $db->transaction(function() use ($db) {
        $db->exec("INSERT INTO test_users (name, count) VALUES (?, ?)", ['Carol', 3]);

        // Nested transaction
        $nestedResult = $db->transaction(function() use ($db) {
            $db->exec("UPDATE test_users SET count = count + 10 WHERE name = ?", ['Carol']);
            return 'nested_success';
        });

        assertEqual('nested_success', $nestedResult);
        return 'outer_success';
    });

    assertEqual('outer_success', $result);

    // Verify both operations were committed
    $user = $db->queryOne("SELECT * FROM test_users WHERE name = ?", ['Carol']);
    assertEqual('Carol', $user['name']);
    assertEqual(13, (int)$user['count']); // 3 + 10
});

// Test nested transaction rollback
test('Nested transaction rollback affects entire transaction', function() use ($db) {
    try {
        $db->transaction(function() use ($db) {
            $db->exec("INSERT INTO test_users (name, count) VALUES (?, ?)", ['David', 4]);

            // Nested transaction that fails
            $db->transaction(function() use ($db) {
                $db->exec("UPDATE test_users SET count = count + 10 WHERE name = ?", ['David']);
                throw new Exception("Nested failure");
            });
        });
    } catch (Exception $e) {
        // Exception expected
    }

    // Verify entire transaction was rolled back
    $user = $db->queryOne("SELECT * FROM test_users WHERE name = ?", ['David']);
    assertEqual(null, $user);
});

echo "All transaction tests passed!\n";