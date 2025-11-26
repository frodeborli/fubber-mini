<?php
/**
 * PartialQuery Hydrator Examples
 *
 * Demonstrates returning typed objects instead of associative arrays.
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
        age INTEGER,
        created_at TEXT
    )
');

$pdo->exec("INSERT INTO users (name, email, age, created_at) VALUES
    ('John Doe', 'john@example.com', 30, '2024-01-15'),
    ('Jane Smith', 'jane@example.com', 25, '2024-02-20'),
    ('Bob Johnson', 'bob@example.com', 35, '2024-01-10')
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

echo "=== PartialQuery Hydrator Examples ===\n\n";

// Example 1: Simple class hydration with public properties
echo "1. Class hydration (public properties):\n";

class User {
    public int $id;
    public string $name;
    public string $email;
    public int $age;
    public string $created_at;

    public function greet(): string {
        return "Hello, I'm {$this->name}!";
    }
}

$users = $db->table('users')->withEntityClass(User::class);
foreach ($users as $user) {
    echo "   - {$user->greet()} (age {$user->age})\n";
}
echo "\n";

// Example 2: Closure hydration with constructor
echo "2. Closure hydration (constructor parameters):\n";

class UserWithConstructor {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly int $age,
        public readonly string $created_at
    ) {}

    public function isAdult(): bool {
        return $this->age >= 18;
    }
}

$users = $db->table('users')->withHydrator(
    fn($id, $name, $email, $age, $created_at) => new UserWithConstructor($id, $name, $email, $age, $created_at)
);

foreach ($users as $user) {
    $status = $user->isAdult() ? 'adult' : 'minor';
    echo "   - {$user->name}: {$status}\n";
}
echo "\n";

// Example 3: Composable queries with hydration
echo "3. Composable queries with hydration:\n";

class UserScope {
    /**
     * @return mini\Database\PartialQuery<User>
     */
    public static function all($db): mini\Database\PartialQuery {
        return $db->table('users')->withEntityClass(User::class);
    }

    /**
     * @return mini\Database\PartialQuery<User>
     */
    public static function adults($db): mini\Database\PartialQuery {
        return self::all($db)->gte('age', 18);
    }

    /**
     * @return mini\Database\PartialQuery<User>
     */
    public static function youngAdults($db): mini\Database\PartialQuery {
        return self::adults($db)->lt('age', 30);
    }
}

$youngAdults = UserScope::youngAdults($db);
echo "   Young adults: {$youngAdults->count()}\n";
foreach ($youngAdults as $user) {
    echo "   - {$user->name} (age {$user->age})\n";
}
echo "\n";

// Example 4: one() method with hydration
echo "4. Fetch single object with one():\n";
$user = $db->table('users')
    ->withEntityClass(User::class)
    ->eq('name', 'John Doe')
    ->one();

if ($user) {
    echo "   Found: {$user->greet()}\n";
}
echo "\n";

// Example 5: Hydration cleared by select()
echo "5. Hydration cleared when using select():\n";
$names = $db->table('users')
    ->withEntityClass(User::class)  // Entity class set
    ->select('name')                 // Entity class cleared
    ->column();

echo "   Names (as plain array): " . implode(', ', $names) . "\n\n";

// Example 6: Class with constructor arguments
echo "6. Class hydration with constructor dependencies:\n";

class UserWithDB {
    public int $id;
    public string $name;
    public string $email;
    public int $age;
    public string $created_at;

    public function __construct(private string $prefix) {}

    public function getDisplayName(): string {
        return $this->prefix . $this->name;
    }
}

$users = $db->table('users')->withEntityClass(UserWithDB::class, ['Mr. ']);
foreach ($users as $user) {
    echo "   - {$user->getDisplayName()}\n";
}
echo "\n";

echo "=== All examples complete ===\n";
