<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Tables\DatabaseRepository;
use mini\Table;
use mini\DB;

// Debug Table class
class DebugTable extends Table {
    public function getConditions(): array {
        return $this->conditions ?? [];
    }

    public function getRepository() {
        return $this->repository;
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

$repo = new DatabaseRepository($db, 'test_dates', TestUser::class, 'id', [
    'created_at' => 'datetime'
]);

// Test the query method step by step
echo "1. Creating initial table:\n";
$table = new DebugTable($repo);
echo "Initial conditions: " . var_export($table->getConditions(), true) . "\n";

echo "\n2. Calling query method:\n";
$filtered = $table->query('created_at:gte=2024-01-01');
echo "Filtered conditions: " . var_export($filtered->getConditions(), true) . "\n";

echo "\n3. Testing if filtered is a DebugTable:\n";
echo "Class: " . get_class($filtered) . "\n";

if ($filtered instanceof DebugTable) {
    echo "Filtered table conditions: " . var_export($filtered->getConditions(), true) . "\n";
} else {
    echo "Filtered table is regular Table - can't debug conditions\n";
}