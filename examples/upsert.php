<?php
/**
 * Upsert (INSERT or UPDATE) Examples
 *
 * Demonstrates the upsert() method for inserting or updating rows.
 */

require __DIR__ . '/../vendor/autoload.php';

// Setup test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        login_count INTEGER DEFAULT 0,
        updated_at TEXT
    )
');

$pdo->exec('
    CREATE TABLE user_prefs (
        user_id INTEGER NOT NULL,
        pref_key TEXT NOT NULL,
        pref_value TEXT NOT NULL,
        PRIMARY KEY (user_id, pref_key)
    )
');

// Create database instance
$db = new mini\Database\PDODatabase($pdo);

echo "=== Upsert Examples ===\n\n";

// Example 1: Insert new user
echo "1. Insert new user:\n";
$affected = $db->upsert('users', [
    'email' => 'john@example.com',
    'name' => 'John Doe',
    'login_count' => 0,
    'updated_at' => date('Y-m-d H:i:s')
], 'email');

echo "   Affected rows: $affected\n";
echo "   Last insert ID: " . $db->lastInsertId() . "\n";

$user = $db->queryOne("SELECT * FROM users WHERE email = ?", ['john@example.com']);
echo "   User: {$user->name} ({$user->email}) - login_count: {$user->login_count}\n\n";

// Example 2: Update existing user (same email)
echo "2. Update existing user (upsert with same email):\n";
$affected = $db->upsert('users', [
    'email' => 'john@example.com',
    'name' => 'John Updated',
    'login_count' => 5,
    'updated_at' => date('Y-m-d H:i:s')
], 'email');

echo "   Affected rows: $affected\n";
$user = $db->queryOne("SELECT * FROM users WHERE email = ?", ['john@example.com']);
echo "   User: {$user->name} ({$user->email}) - login_count: {$user->login_count}\n";
echo "   User ID unchanged: {$user->id}\n\n";

// Example 3: Composite unique key
echo "3. Upsert with composite key (user preferences):\n";

// Insert preference
$affected = $db->upsert('user_prefs', [
    'user_id' => 1,
    'pref_key' => 'theme',
    'pref_value' => 'light'
], 'user_id', 'pref_key');
echo "   Insert theme preference - affected: $affected\n";

// Update same preference
$affected = $db->upsert('user_prefs', [
    'user_id' => 1,
    'pref_key' => 'theme',
    'pref_value' => 'dark'
], 'user_id', 'pref_key');
echo "   Update theme preference - affected: $affected\n";

$pref = $db->queryOne("SELECT * FROM user_prefs WHERE user_id = ? AND pref_key = ?", [1, 'theme']);
echo "   Theme preference: {$pref->pref_value}\n\n";

// Example 4: Multiple upserts in transaction
echo "4. Multiple upserts in transaction:\n";
$db->transaction(function($db) {
    $db->upsert('users', [
        'email' => 'jane@example.com',
        'name' => 'Jane Smith',
        'login_count' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ], 'email');

    $db->upsert('users', [
        'email' => 'bob@example.com',
        'name' => 'Bob Johnson',
        'login_count' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ], 'email');
});

$count = $db->queryField("SELECT COUNT(*) FROM users");
echo "   Total users: $count\n\n";

// Example 5: Upsert returning proper affected count
echo "5. Check affected rows behavior:\n";

// First upsert - insert
$affected1 = $db->upsert('users', [
    'email' => 'alice@example.com',
    'name' => 'Alice Brown',
    'login_count' => 0,
    'updated_at' => date('Y-m-d H:i:s')
], 'email');
echo "   First upsert (INSERT): $affected1 row(s)\n";

// Second upsert - update
$affected2 = $db->upsert('users', [
    'email' => 'alice@example.com',
    'name' => 'Alice Updated',
    'login_count' => 10,
    'updated_at' => date('Y-m-d H:i:s')
], 'email');
echo "   Second upsert (UPDATE): $affected2 row(s)\n";

// Third upsert - no change
$affected3 = $db->upsert('users', [
    'email' => 'alice@example.com',
    'name' => 'Alice Updated',
    'login_count' => 10,
    'updated_at' => date('Y-m-d H:i:s')
], 'email');
echo "   Third upsert (NO CHANGE): $affected3 row(s)\n\n";

echo "=== All examples complete ===\n";
