<?php
/**
 * ModelTrait Example
 *
 * Demonstrates using ModelTrait for Eloquent-style models.
 */

require __DIR__ . '/../vendor/autoload.php';

// Setup test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        status TEXT DEFAULT "active",
        created_at TEXT
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

        $updateParts = [];
        foreach ($columns as $column) {
            if (!in_array($column, $conflictColumns)) {
                $updateParts[] = "$column = excluded.$column";
            }
        }

        if (empty($updateParts)) {
            $sql = "INSERT INTO $table ($columnList) VALUES ($placeholders) ON CONFLICT ($conflictList) DO NOTHING";
        } else {
            $updateClause = implode(', ', $updateParts);
            $sql = "INSERT INTO $table ($columnList) VALUES ($placeholders) ON CONFLICT ($conflictList) DO UPDATE SET $updateClause";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount();
    }
};

// Helper to inject our database instance for the db() function
// In real applications, this would be configured in _config/mini/Database/DatabaseInterface.php
function db(): mini\Database\DatabaseInterface {
    global $db;
    return $db;
}

// Define User model
class User {
    use mini\Database\ModelTrait;

    public ?int $id = null;
    public string $name;
    public string $email;
    public string $status = 'active';
    public ?string $created_at = null;

    protected static function getTableName(): string {
        return 'users';
    }

    protected static function getPrimaryKey(): string {
        return 'id';
    }

    protected static function getEntityClass(): string {
        return self::class;
    }

    protected static function dehydrate(object $entity): array {
        $data = [
            'name' => $entity->name,
            'email' => $entity->email,
            'status' => $entity->status,
            'created_at' => $entity->created_at ?? date('Y-m-d H:i:s')
        ];

        // Only include ID if it's set (for updates)
        if ($entity->id !== null) {
            $data['id'] = $entity->id;
        }

        return $data;
    }

    // Custom query scopes
    /** @return mini\Database\PartialQuery<User> */
    public static function active(): mini\Database\PartialQuery {
        return self::query()->eq('status', 'active');
    }

    /** @return mini\Database\PartialQuery<User> */
    public static function inactive(): mini\Database\PartialQuery {
        return self::query()->eq('status', 'inactive');
    }
}

echo "=== ModelTrait Examples ===\n\n";

// Example 1: Create and save new model
echo "1. Create and save new user:\n";
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->status = 'active';
$affected = $user->save();

echo "   Affected rows: $affected\n";
echo "   User ID after save: {$user->id}\n";
echo "   Created at: {$user->created_at}\n\n";

// Example 2: Find by ID
echo "2. Find user by ID:\n";
$found = User::find($user->id);
echo "   Found: {$found->name} ({$found->email})\n\n";

// Example 3: Update and save
echo "3. Update existing user:\n";
$found->name = 'John Updated';
$affected = $found->save();
echo "   Affected rows: $affected\n";
echo "   ID unchanged: {$found->id}\n";

// Verify update
$verified = User::find($found->id);
echo "   Verified name: {$verified->name}\n\n";

// Example 4: Query with scopes
echo "4. Create more users and query with scopes:\n";

$user2 = new User();
$user2->name = 'Jane Smith';
$user2->email = 'jane@example.com';
$user2->status = 'active';
$user2->save();

$user3 = new User();
$user3->name = 'Bob Inactive';
$user3->email = 'bob@example.com';
$user3->status = 'inactive';
$user3->save();

echo "   All users: " . User::query()->count() . "\n";
echo "   Active users: " . User::active()->count() . "\n";
echo "   Inactive users: " . User::inactive()->count() . "\n\n";

// Example 5: Iterate with typed results
echo "5. Iterate through active users:\n";
foreach (User::active() as $activeUser) {
    echo "   - {$activeUser->name} ({$activeUser->status})\n";
}
echo "\n";

// Example 6: Delete
echo "6. Delete user:\n";
$toDelete = User::find($user3->id);
echo "   Deleting: {$toDelete->name}\n";
$affected = $toDelete->delete();
echo "   Affected rows: $affected\n";
echo "   Remaining users: " . User::query()->count() . "\n\n";

echo "=== All examples complete ===\n";
