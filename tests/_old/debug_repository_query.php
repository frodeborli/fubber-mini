<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Tables\DatabaseRepository;
use mini\Util\InstanceStore;
use mini\Tables\RepositoryInterface;
use mini\Database\DatabaseInterface;
use mini\Database\PDODatabase;

class TestUser {
    public ?int $id = null;
    public ?string $name = null;
    public ?int $age = null;
}

$db = new PDODatabase(new PDO('sqlite::memory:'));
$db->exec("CREATE TABLE debug_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100),
    age INTEGER
)");

$db->exec("INSERT INTO debug_users (name, age) VALUES
    ('Alice', 25),
    ('Bob', 35),
    ('Carol', 45),
    ('David', 19),
    ('Eve', 67)");

$testRegistry = new InstanceStore(RepositoryInterface::class);
$testRegistry['users'] = new DatabaseRepository($db, 'debug_users', TestUser::class);

// Test the actual query conditions being generated
$table = $testRegistry->get('users')->all();

echo "All users:\n";
foreach ($table as $user) {
    echo "- {$user->name} (age: {$user->age})\n";
}

echo "\nUsers with age >= 21 (should be 4: Alice, Bob, Carol, Eve):\n";
$adults = $table->query('age:gte=21');
echo "Count: " . $adults->count() . "\n";
foreach ($adults as $user) {
    echo "- {$user->name} (age: {$user->age})\n";
}

// Let me check what ages we actually have
echo "\nActual ages in database:\n";
$allAges = $db->query('SELECT name, age FROM debug_users ORDER BY age');
foreach ($allAges as $row) {
    $shouldMatch = $row['age'] >= 21 ? ' ✓' : ' ✗';
    echo "- {$row['name']}: {$row['age']}$shouldMatch\n";
}
