<?php
/**
 * Query Hydrator Examples
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
$db = new mini\Database\PDODatabase($pdo);

echo "=== Query Hydrator Examples ===\n\n";

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

$users = $db->query('SELECT * FROM users')->withEntityClass(User::class);
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

$users = $db->query('SELECT * FROM users')->withHydrator(
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
     * @return mini\Database\Query<User>
     */
    public static function all($db): mini\Database\Query {
        return $db->query('SELECT * FROM users')->withEntityClass(User::class);
    }

    /**
     * @return mini\Database\Query<User>
     */
    public static function adults($db): mini\Database\Query {
        return self::all($db)->gte('age', 18);
    }

    /**
     * @return mini\Database\Query<User>
     */
    public static function youngAdults($db): mini\Database\Query {
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
$user = $db->query('SELECT * FROM users')
    ->withEntityClass(User::class)
    ->eq('name', 'John Doe')
    ->one();

if ($user) {
    echo "   Found: {$user->greet()}\n";
}
echo "\n";

// Example 5: Column projection returns plain values
echo "5. Column projection with columns():\n";
$names = $db->query('SELECT * FROM users')
    ->withEntityClass(User::class)  // Entity class set
    ->columns('name')                // project to a single column
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

$users = $db->query('SELECT * FROM users')->withEntityClass(UserWithDB::class, ['Mr. ']);
foreach ($users as $user) {
    echo "   - {$user->getDisplayName()}\n";
}
echo "\n";

echo "=== All examples complete ===\n";
