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

echo "Testing Table conditions step by step:\n";

echo "\n1. Initial table:\n";
$table = $repo->all();
echo "Conditions: " . var_export($table->debugConditions(), true) . "\n";

echo "\n2. After query():\n";
$filtered = $table->query('created_at:gte=2024-01-01');
echo "Conditions: " . var_export($filtered->debugConditions(), true) . "\n";

echo "\n3. Count check:\n";
echo "Filtered count: " . $filtered->count() . "\n";

echo "\n4. Manual fetchMany test:\n";
$conditions = $filtered->debugConditions();
$manualResults = $repo->fetchMany($conditions);
echo "Manual fetchMany count: " . count(iterator_to_array($manualResults)) . "\n";