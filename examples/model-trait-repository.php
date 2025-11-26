<?php
/**
 * ModelTrait Repository Pattern Example
 *
 * Demonstrates using ModelTrait with separate repository class and POPO entities.
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
        return 0; // Not used in this example
    }
};

// Helper to inject our database instance for the db() function
function db(): mini\Database\DatabaseInterface {
    global $db;
    return $db;
}

// Define POPO (Plain Old PHP Object) - no framework dependencies
class User {
    public ?int $id = null;
    public string $name;
    public string $email;
    public string $status = 'active';
    public ?string $created_at = null;

    // Entity can have business logic methods
    public function isActive(): bool {
        return $this->status === 'active';
    }
}

// Define Repository class with RepositoryTrait
/**
 * @use mini\Database\RepositoryTrait<User>
 */
class Users {
    use mini\Database\RepositoryTrait;

    protected static function getTableName(): string {
        return 'users';
    }

    protected static function getPrimaryKey(): string {
        return 'id';
    }

    protected static function getEntityClass(): string {
        return User::class;
    }

    protected static function dehydrate(object $entity): array {
        /** @var User $entity */
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

echo "=== ModelTrait Repository Pattern Examples ===\n\n";

// Example 1: Create and persist new entity
echo "1. Create and persist new user (repository pattern):\n";
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->status = 'active';

$affected = Users::persist($user);
echo "   Affected rows: $affected\n";
echo "   User ID after persist: {$user->id}\n";
echo "   Created at: {$user->created_at}\n\n";

// Example 2: Find by ID (returns typed object)
echo "2. Find user by ID:\n";
$found = Users::find($user->id);
echo "   Found: {$found->name} ({$found->email})\n";
echo "   Is active: " . ($found->isActive() ? 'yes' : 'no') . "\n\n";

// Example 3: Update and persist
echo "3. Update existing user:\n";
$found->name = 'John Updated';
$affected = Users::persist($found);
echo "   Affected rows: $affected\n";
echo "   ID unchanged: {$found->id}\n";

// Verify update
$verified = Users::find($found->id);
echo "   Verified name: {$verified->name}\n\n";

// Example 4: Query with scopes (returns typed results)
echo "4. Create more users and query with scopes:\n";

$user2 = new User();
$user2->name = 'Jane Smith';
$user2->email = 'jane@example.com';
$user2->status = 'active';
Users::persist($user2);

$user3 = new User();
$user3->name = 'Bob Inactive';
$user3->email = 'bob@example.com';
$user3->status = 'inactive';
Users::persist($user3);

echo "   All users: " . Users::query()->count() . "\n";
echo "   Active users: " . Users::active()->count() . "\n";
echo "   Inactive users: " . Users::inactive()->count() . "\n\n";

// Example 5: Iterate with typed results
echo "5. Iterate through active users:\n";
foreach (Users::active() as $activeUser) {
    echo "   - {$activeUser->name} ({$activeUser->status}) - isActive(): " . ($activeUser->isActive() ? 'true' : 'false') . "\n";
}
echo "\n";

// Example 6: Remove (delete)
echo "6. Remove user:\n";
$toDelete = Users::find($user3->id);
echo "   Removing: {$toDelete->name}\n";
$affected = Users::remove($toDelete);
echo "   Affected rows: $affected\n";
echo "   Remaining users: " . Users::query()->count() . "\n\n";

// Example 7: POPO remains framework-independent
echo "7. POPO is framework-independent:\n";
$pureEntity = new User();
$pureEntity->name = 'Pure POPO';
$pureEntity->email = 'popo@example.com';
echo "   Created POPO without touching database\n";
echo "   Has business logic: isActive() = " . ($pureEntity->isActive() ? 'true' : 'false') . "\n";
echo "   No framework methods on entity (save/delete not available on entity)\n\n";

echo "=== All examples complete ===\n";
