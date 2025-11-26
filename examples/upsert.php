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

    public function getDialect(): mini\Database\SqlDialect {
        return mini\Database\SqlDialect::Sqlite;
    }

    public function delete(mini\Database\PartialQuery $query): int {
        $where = $query->getWhere();
        if (empty($where['sql'])) {
            throw new \InvalidArgumentException("DELETE requires WHERE clause");
        }
        $sql = "DELETE FROM {$query->getTable()} WHERE {$where['sql']} LIMIT {$query->getLimit()}";
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
        if (empty($data)) {
            throw new \InvalidArgumentException("Data array cannot be empty for insert");
        }

        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $columnList = implode(', ', $columns);
        $sql = "INSERT INTO $table ($columnList) VALUES ($placeholders)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $this->lastInsertId() ?? '';
    }

    public function upsert(string $table, array $data, string ...$conflictColumns): int {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data array cannot be empty for upsert");
        }
        if (empty($conflictColumns)) {
            throw new \InvalidArgumentException("At least one conflict column must be specified for upsert");
        }

        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $columnList = implode(', ', $columns);
        $conflictList = implode(', ', $conflictColumns);

        // SQLite uses Postgres-style syntax
        $updateParts = [];
        foreach ($columns as $column) {
            if (!in_array($column, $conflictColumns)) {
                $updateParts[] = "$column = excluded.$column";
            }
        }

        if (empty($updateParts)) {
            $sql = "INSERT INTO $table ($columnList) VALUES ($placeholders) ON CONFLICT ($conflictList) DO NOTHING";
            $params = $values;
        } else {
            $updateClause = implode(', ', $updateParts);
            $sql = "INSERT INTO $table ($columnList) VALUES ($placeholders) ON CONFLICT ($conflictList) DO UPDATE SET $updateClause";
            $params = $values; // SQLite uses EXCLUDED.column, not placeholders
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
};

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
echo "   User: {$user['name']} ({$user['email']}) - login_count: {$user['login_count']}\n\n";

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
echo "   User: {$user['name']} ({$user['email']}) - login_count: {$user['login_count']}\n";
echo "   User ID unchanged: {$user['id']}\n\n";

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
echo "   Theme preference: {$pref['pref_value']}\n\n";

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
