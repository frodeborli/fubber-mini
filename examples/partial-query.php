<?php
/**
 * Query Examples
 *
 * Demonstrates the immutable, composable query facade returned by
 * DatabaseInterface::query(). WHERE/ORDER/LIMIT clauses compose onto
 * any base SQL, and each method returns a new instance - so queries
 * can be passed around safely and only ever narrowed, never widened.
 */

require __DIR__ . '/../vendor/autoload.php';

// Setup test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT,
        active INTEGER DEFAULT 1,
        role TEXT DEFAULT "user",
        age INTEGER,
        created_at TEXT
    )
');

$pdo->exec("INSERT INTO users (name, email, active, role, age, created_at) VALUES
    ('John Doe', 'john@example.com', 1, 'admin', 30, '2024-01-15'),
    ('Jane Smith', 'jane@example.com', 1, 'user', 25, '2024-02-20'),
    ('Bob Johnson', 'bob@example.com', 0, 'user', 35, '2024-01-10'),
    ('Alice Brown', 'alice@example.com', 1, 'moderator', 28, '2024-03-01'),
    ('Charlie Wilson', 'charlie@example.com', 1, 'user', 42, '2023-12-05')
");

$db = new mini\Database\PDODatabase($pdo);

echo "=== Query Examples ===\n\n";

// Example 1: Basic usage
echo "1. Basic query:\n";
$users = $db->query('SELECT * FROM users')
    ->eq('active', 1)
    ->order('created_at DESC')
    ->limit(3);

echo "   SQL: {$users}\n";
foreach ($users as $user) {
    echo "   - {$user->name} ({$user->email})\n";
}

// Example 2: Composable queries (reusable scopes)
echo "\n2. Composable queries:\n";

class User {
    public static function active($db): mini\Database\Query {
        return $db->query('SELECT * FROM users')->eq('active', 1);
    }

    public static function admins($db): mini\Database\Query {
        return self::active($db)->eq('role', 'admin');
    }
}

$activeUsers = User::active($db);
$admins = User::admins($db);

echo "   Active users: {$activeUsers->count()}\n";
echo "   Admins: {$admins->count()}\n";

// Example 3: Immutability
echo "\n3. Immutability (base query unchanged):\n";
$base = $db->query('SELECT * FROM users');
$adults = $base->gte('age', 30);
$young = $base->lt('age', 30);

echo "   Total: {$base->count()}\n";
echo "   Adults (30+): {$adults->count()}\n";
echo "   Young (<30): {$young->count()}\n";

// Example 4: Complex filtering
echo "\n4. Complex filtering:\n";
$filtered = $db->query('SELECT * FROM users')
    ->eq('active', 1)
    ->gte('age', 25)
    ->lte('age', 35)
    ->in('role', ['user', 'moderator'])
    ->order('age DESC');

echo "   SQL: {$filtered}\n";
echo "   Found: {$filtered->count()} users\n";
foreach ($filtered as $user) {
    echo "   - {$user->name} (age {$user->age}, {$user->role})\n";
}

// Example 5: one() method
echo "\n5. Fetch single row:\n";
$admin = $db->query('SELECT * FROM users')->eq('role', 'admin')->one();
echo "   Admin: {$admin->name}\n";

// Example 6: column() method
echo "\n6. Fetch column:\n";
$names = $db->query('SELECT * FROM users')
    ->eq('active', 1)
    ->columns('name')
    ->order('age DESC')
    ->column();
echo "   Names: " . implode(', ', $names) . "\n";

// Example 7: Debugging via string cast
echo "\n7. Debugging:\n";
$query = $db->query('SELECT * FROM users')
    ->eq('active', 1)
    ->gte('age', 30);

echo "   String cast: {$query}\n";

// Example 8: Multi-tenancy pattern
echo "\n8. Multi-tenancy helper:\n";

function tenantQuery($db, string $table, int $tenantId): mini\Database\Query {
    return $db->query('SELECT * FROM ' . $db->quoteIdentifier($table))->eq('tenant_id', $tenantId);
}

// Simulate multi-tenant usage (would use session in real app)
// $orders = tenantQuery($db, 'orders', $_SESSION['tenant_id'])
//     ->gte('total', 100)
//     ->order('created_at DESC');

echo "   Pattern demonstrated (see source)\n";

echo "\n=== Examples complete ===\n";
