<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Tables\DatabaseRepository;
use mini\DB;

// Extend to debug conditions
class DebugRepository extends DatabaseRepository {
    public function fetchMany(array $where, array $order = [], ?int $limit = null, int $offset = 0): iterable {
        echo "FetchMany called with conditions: " . var_export($where, true) . "\n";
        return parent::fetchMany($where, $order, $limit, $offset);
    }
}

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

$repo = new DebugRepository($db, 'test_dates', TestUser::class, 'id', [
    'created_at' => 'datetime'
]);

echo "Testing Table query flow:\n";
$table = $repo->all();
echo "Initial table conditions: " . var_export($table->getIterator()->current(), true) . "\n";

$filtered = $table->query('created_at:gte=2024-01-01');

echo "\nIterating through filtered table:\n";
foreach ($filtered as $user) {
    echo "- {$user->name}: {$user->created_at->format('Y-m-d H:i:s')}\n";
}