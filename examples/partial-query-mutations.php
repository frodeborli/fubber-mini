<?php
/**
 * PartialQuery DELETE and UPDATE Examples
 *
 * Demonstrates composable DELETE and UPDATE operations using PartialQuery.
 */

require __DIR__ . '/../vendor/autoload.php';

// Setup test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT,
        status TEXT DEFAULT "active",
        login_count INTEGER DEFAULT 0,
        last_login TEXT,
        created_at TEXT
    )
');

$pdo->exec("INSERT INTO users (name, email, status, login_count, last_login, created_at) VALUES
    ('John Doe', 'john@example.com', 'active', 10, '2025-01-20', '2024-01-15'),
    ('Jane Smith', 'jane@example.com', 'inactive', 5, '2024-12-01', '2024-02-20'),
    ('Bob Johnson', 'bob@example.com', 'inactive', 0, NULL, '2023-01-10'),
    ('Alice Brown', 'alice@example.com', 'pending', 1, '2025-01-19', '2025-01-01'),
    ('Charlie Wilson', 'charlie@example.com', 'spam', 0, NULL, '2023-12-05')
");

// Create database instance
$db = new class($pdo) implements mini\Database\DatabaseInterface {
    use mini\Database\PartialQueryableTrait;

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function query(string $sql, array $params = []): iterable {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) { yield $row; }
    }

    public function queryOne(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function queryField(string $sql, array $params = []): mixed {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }


    public function queryColumn(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function exec(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): ?string {
        return $this->pdo->lastInsertId();
    }

    public function tableExists(string $tableName): bool {
        return true;
    }

    public function transaction(\Closure $task): mixed {
        return $task($this);
    }

    public function quote(mixed $value): string {
        if ($value === null) return 'NULL';
        if (is_int($value)) return $this->pdo->quote($value, PDO::PARAM_INT);
        if (is_bool($value)) return $this->pdo->quote($value, PDO::PARAM_BOOL);
        return $this->pdo->quote($value, PDO::PARAM_STR);
    }
    public function getDialect(): mini\Database\SqlDialect { return mini\Database\SqlDialect::Sqlite; }

    public function delete(mini\Database\PartialQuery $query): int {
        $where = $query->getWhere();
        $sql = "DELETE FROM {$query->getTable()}";
        if ($where['sql']) $sql .= " WHERE {$where['sql']}";
        $sql .= " LIMIT {$query->getLimit()}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->rowCount();
    }

    public function update(mini\Database\PartialQuery $query, string|array $set): int {
        $where = $query->getWhere();

        if (is_string($set)) {
            $sql = "UPDATE {$query->getTable()} SET {$set}";
            $params = $where['params'];
        } else {
            $setParts = [];
            $setParams = [];
            foreach ($set as $column => $value) {
                $setParts[] = "$column = ?";
                $setParams[] = $value;
            }
            $sql = "UPDATE {$query->getTable()} SET " . implode(', ', $setParts);
            $params = array_merge($setParams, $where['params']);
        }

        if ($where['sql']) $sql .= " WHERE {$where['sql']}";
        $sql .= " LIMIT {$query->getLimit()}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $table, array $data): string {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $this->lastInsertId() ?? '';
    }

    public function upsert(string $table, array $data, string ...$conflictColumns): int {
        return 0; // Not used in this example
    }
};

echo "=== PartialQuery DELETE/UPDATE Examples ===\n\n";

// Define reusable scopes
class User {
    public static function query($db): mini\Database\PartialQuery {
        return $db->table('users');
    }

    public static function spam($db): mini\Database\PartialQuery {
        return self::query($db)->eq('status', 'spam');
    }

    public static function inactive($db): mini\Database\PartialQuery {
        return self::query($db)->eq('status', 'inactive');
    }

    public static function pending($db): mini\Database\PartialQuery {
        return self::query($db)->eq('status', 'pending');
    }
}

echo "Initial state:\n";
echo "Total users: " . User::query($db)->count() . "\n";
echo "Spam: " . User::spam($db)->count() . "\n";
echo "Inactive: " . User::inactive($db)->count() . "\n";
echo "Pending: " . User::pending($db)->count() . "\n\n";

// Example 1: Delete spam users
echo "1. Delete spam users:\n";
$deleted = $db->delete(User::spam($db));
echo "   Deleted: $deleted rows\n";
echo "   Remaining: " . User::query($db)->count() . " users\n\n";

// Example 2: Update with array (simple assignments)
echo "2. Update inactive users to 'archived':\n";
$updated = $db->update(
    User::inactive($db),
    ['status' => 'archived', 'last_login' => date('Y-m-d H:i:s')]
);
echo "   Updated: $updated rows\n";
$archived = iterator_to_array(User::query($db)->eq('status', 'archived'));
foreach ($archived as $user) {
    echo "   - {$user['name']}: {$user['status']}\n";
}
echo "\n";

// Example 3: Update with raw SQL expression
echo "3. Increment login_count for active users:\n";
$updated = $db->update(
    User::query($db)->eq('status', 'active'),
    'login_count = login_count + 1'
);
echo "   Updated: $updated rows\n";
$active = iterator_to_array(User::query($db)->eq('status', 'active'));
foreach ($active as $user) {
    echo "   - {$user['name']}: login_count = {$user['login_count']}\n";
}
echo "\n";

// Example 4: Composable delete with additional filters
echo "4. Delete old inactive accounts:\n";
$query = User::inactive($db)
    ->where('created_at < ?', ['2024-01-01'])
    ->where('login_count = ?', [0]);

echo "   Query: " . $query->__toString() . "\n";
echo "   (No rows match this criteria after our updates)\n\n";

// Example 5: Update pending users
echo "5. Activate pending users:\n";
$updated = $db->update(
    User::pending($db),
    ['status' => 'active']
);
echo "   Updated: $updated rows\n\n";

echo "Final state:\n";
echo "Total users: " . User::query($db)->count() . "\n";
echo "Active: " . User::query($db)->eq('status', 'active')->count() . "\n";
echo "Archived: " . User::query($db)->eq('status', 'archived')->count() . "\n";
echo "Spam: " . User::spam($db)->count() . "\n\n";

echo "=== All examples complete ===\n";
