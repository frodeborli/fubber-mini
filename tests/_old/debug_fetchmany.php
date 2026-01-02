<?php

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Tables\DatabaseRepository;
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

echo "Testing fetchMany method directly:\n";

// Test fetchMany with the exact conditions that should be generated
$conditions = ['created_at:gte' => '2024-01-01'];
$results = $repo->fetchMany($conditions);

echo "FetchMany results:\n";
foreach ($results as $row) {
    echo "- {$row['name']}: {$row['created_at']}\n";
}
echo "Count: " . count(iterator_to_array($repo->fetchMany($conditions))) . "\n";

echo "\nTesting Table query method:\n";
$table = $repo->all();
$filtered = $table->query('created_at:gte=2024-01-01');

echo "Table results:\n";
foreach ($filtered as $user) {
    echo "- {$user->name}: {$user->created_at->format('Y-m-d H:i:s')}\n";
}
echo "Count: " . $filtered->count() . "\n";