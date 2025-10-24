<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Repository\DatabaseRepository;
use mini\DB;

class TestUser {
    public ?int $id = null;
    public ?string $name = null;
    public ?\DateTimeImmutable $created_at = null;
}

$db = new DB(':memory:');
$db->exec("CREATE TABLE test_dates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100),
    created_at DATETIME
)");

$db->exec("INSERT INTO test_dates (name, created_at) VALUES
    ('Alice', '2024-01-15 10:30:00'),
    ('Bob', '2023-06-20 14:45:00'),
    ('Carol', '2024-03-10 09:15:00')");

$repo = new DatabaseRepository($db, 'test_dates', TestUser::class, 'id', [
    'created_at' => 'datetime'
]);

echo "Testing datetime conversion:\n";

// Test string conversion
$stringValue = '2024-01-01';
$converted = $repo->convertConditionValue('created_at', $stringValue);
echo "String '2024-01-01' converts to: " . var_export($converted, true) . "\n";

// Test what's actually in the database
echo "\nDatabase values:\n";
$rows = $db->query('SELECT name, created_at FROM test_dates ORDER BY created_at');
foreach ($rows as $row) {
    $shouldMatch = $row['created_at'] >= '2024-01-01 00:00:00' ? ' ✓' : ' ✗';
    echo "- {$row['name']}: {$row['created_at']}$shouldMatch\n";
}

// Test the actual query
echo "\nQuery test:\n";
$all = $repo->all();
$filtered = $all->query('created_at:gte=2024-01-01');
echo "Found " . $filtered->count() . " users\n";

foreach ($filtered as $user) {
    echo "- {$user->name}: {$user->created_at->format('Y-m-d H:i:s')}\n";
}