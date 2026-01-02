<?php

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Tables\DatabaseRepository;
use mini\DB;

// Extend DatabaseRepository to expose buildWhereClause for debugging
class DebugDatabaseRepository extends DatabaseRepository {
    public function debugBuildWhereClause(array $where): array {
        $params = [];
        $clause = $this->buildWhereClause($where, $params);
        return ['clause' => $clause, 'params' => $params];
    }

    // Make buildWhereClause accessible
    public function buildWhereClause(array $where, array &$params): string {
        return parent::buildWhereClause($where, $params);
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

$repo = new DebugDatabaseRepository($db, 'test_dates', TestUser::class, 'id', [
    'created_at' => 'datetime'
]);

// Test the WHERE clause generation
$conditions = ['created_at:gte' => '2024-01-01'];
$debug = $repo->debugBuildWhereClause($conditions);

echo "WHERE clause: " . $debug['clause'] . "\n";
echo "Parameters: " . var_export($debug['params'], true) . "\n";

// Test the actual SQL query
$sql = "SELECT * FROM test_dates WHERE " . $debug['clause'];
echo "\nFull SQL: $sql\n";

$result = $db->query($sql, $debug['params']);
echo "Results:\n";
foreach ($result as $row) {
    echo "- {$row['name']}: {$row['created_at']}\n";
}