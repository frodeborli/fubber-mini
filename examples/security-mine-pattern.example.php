<?php
/**
 * Security Pattern: ::mine() as Authorization Boundary
 *
 * Demonstrates the ::mine() pattern for preventing accidental data leaks
 * by making authorization the default path.
 *
 * KEY INSIGHT: If ::mine() is shorter and easier than ::query(),
 * developers will naturally use the secure method!
 */

require __DIR__ . '/../vendor/autoload.php';

// Setup test database with users and friendships
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        bio TEXT
    )
');

$pdo->exec('
    CREATE TABLE friendships (
        user_id INTEGER NOT NULL,
        friend_id INTEGER NOT NULL,
        PRIMARY KEY (user_id, friend_id)
    )
');

$pdo->exec('
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        visibility TEXT DEFAULT "public"
    )
');

// Insert test data
$pdo->exec("INSERT INTO users (id, name, email, bio) VALUES
    (1, 'Alice', 'alice@example.com', 'Alice bio'),
    (2, 'Bob', 'bob@example.com', 'Bob bio'),
    (3, 'Charlie', 'charlie@example.com', 'Charlie bio'),
    (4, 'Diana', 'diana@example.com', 'Diana bio')
");

// Alice is friends with Bob and Charlie (but not Diana)
$pdo->exec("INSERT INTO friendships (user_id, friend_id) VALUES
    (1, 2), (2, 1),  -- Alice <-> Bob
    (1, 3), (3, 1)   -- Alice <-> Charlie
");

$pdo->exec("INSERT INTO posts (user_id, title, content, visibility) VALUES
    (1, 'Alice public', 'Content', 'public'),
    (1, 'Alice private', 'Secret', 'private'),
    (2, 'Bob public', 'Content', 'public'),
    (2, 'Bob private', 'Secret', 'private'),
    (4, 'Diana public', 'Content', 'public')
");

// Mock auth system for demonstration
class MockAuth {
    private static ?int $userId = null;

    public static function login(int $userId): void {
        self::$userId = $userId;
        echo "🔐 Logged in as user {$userId}\n\n";
    }

    public static function getUserId(): int {
        if (self::$userId === null) {
            throw new \RuntimeException('Not authenticated');
        }
        return self::$userId;
    }

    public static function check(): bool {
        return self::$userId !== null;
    }
}

// Override mini\auth() for this example
function auth(): MockAuth {
    return new MockAuth();
}

// Create database instance
$db = new class($pdo) implements mini\Database\DatabaseInterface {
    use mini\Database\PartialQueryableTrait;

    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function query(string $sql, array $params = []): iterable {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) yield $row;
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
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $this->lastInsertId() ?? '';
    }

    public function upsert(string $table, array $data, string ...$conflictColumns): int {
        return 0;
    }
};

// Override mini\db() for this example
function db() {
    global $db;
    return $db;
}

// User entity with ::mine() security boundary
class User {
    public int $id;
    public string $name;
    public string $email;
    public string $bio;

    public static function query(): mini\Database\PartialQuery {
        return db()->table('users')->withEntityClass(User::class, false);
    }

    /**
     * Security boundary: Returns only users accessible to current user
     *
     * Pattern: self + friends
     */
    public static function mine(): mini\Database\PartialQuery {
        $currentUserId = auth()->getUserId();

        // Users can see themselves and their friends
        return self::query()->where('
            id = ? OR EXISTS (
                SELECT 1 FROM friendships
                WHERE (user_id = ? AND friend_id = users.id)
                   OR (friend_id = ? AND user_id = users.id)
            )
        ', [$currentUserId, $currentUserId, $currentUserId]);
    }

    public static function find(int $id): ?User {
        // IMPORTANT: Use ::mine() to enforce authorization
        return self::mine()->eq('id', $id)->one();
    }
}

class Post {
    public int $id;
    public int $user_id;
    public string $title;
    public string $content;
    public string $visibility;

    public static function query(): mini\Database\PartialQuery {
        return db()->table('posts')->withEntityClass(Post::class, false);
    }

    /**
     * Public posts only
     */
    public static function public(): mini\Database\PartialQuery {
        return self::query()->eq('visibility', 'public');
    }

    /**
     * Security boundary: Posts accessible to current user
     *
     * Pattern: public posts + own private posts + friends' private posts
     */
    public static function mine(): mini\Database\PartialQuery {
        if (!auth()->check()) {
            return self::public();
        }

        $userId = auth()->getUserId();

        return self::query()->where('
            visibility = "public"
            OR user_id = ?
            OR (visibility = "private" AND EXISTS (
                SELECT 1 FROM friendships
                WHERE (user_id = ? AND friend_id = posts.user_id)
                   OR (friend_id = ? AND user_id = posts.user_id)
            ))
        ', [$userId, $userId, $userId]);
    }
}

echo "=== Security Pattern: ::mine() as Authorization Boundary ===\n\n";

// Login as Alice (user 1)
MockAuth::login(1);

echo "1. Find users (Alice logged in):\n";
echo "   Alice can see: self + friends (Bob, Charlie)\n";
echo "   Alice CANNOT see: Diana (not friends)\n\n";

foreach (User::mine() as $user) {
    echo "   ✓ {$user->name}\n";
}
echo "\n";

echo "2. Try to access Diana directly (should fail):\n";
$diana = User::find(4);  // Uses ::mine() internally
if ($diana) {
    echo "   ✗ SECURITY VIOLATION: Alice saw Diana!\n";
} else {
    echo "   ✓ Correctly blocked (returns null)\n";
}
echo "\n";

echo "3. Alice can update her own bio:\n";
$affected = db()->update(User::mine()->eq('id', 1), ['bio' => 'Updated by Alice']);
echo "   Rows updated: {$affected}\n\n";

echo "4. Alice CANNOT update Diana's bio:\n";
$affected = db()->update(User::mine()->eq('id', 4), ['bio' => 'Hacked!']);
echo "   Rows updated: {$affected} (blocked by ::mine())\n\n";

echo "5. Posts visible to Alice:\n";
foreach (Post::mine() as $post) {
    echo "   ✓ {$post->title} ({$post->visibility})\n";
}
echo "\n";

echo "6. Search within authorized scope:\n";
$results = User::mine()->where('name LIKE ?', ['%Bob%']);
foreach ($results as $user) {
    echo "   Found: {$user->name}\n";
}
echo "\n";

echo "7. Count authorized users:\n";
$count = User::mine()->count();
echo "   Alice can see {$count} users (self + 2 friends)\n\n";

// Now login as Diana (no friends)
MockAuth::login(4);

echo "8. Find users (Diana logged in):\n";
echo "   Diana can see: only herself (no friends)\n\n";

foreach (User::mine() as $user) {
    echo "   ✓ {$user->name}\n";
}
echo "\n";

echo "9. Diana tries to access Alice:\n";
$alice = User::find(1);
if ($alice) {
    echo "   ✗ SECURITY VIOLATION: Diana saw Alice!\n";
} else {
    echo "   ✓ Correctly blocked (returns null)\n";
}
echo "\n";

echo "=== Pattern Benefits ===\n\n";
echo "✓ Secure by default - ::mine() is shorter than ::query()\n";
echo "✓ Hard to bypass - authorization at query level\n";
echo "✓ Composable - works with all PartialQuery methods\n";
echo "✓ Consistent - same pattern across all entities\n";
echo "✓ Auditable - search for ::query() to find potential issues\n";
echo "✓ Testable - authorization logic in one place\n\n";

echo "=== Key Takeaway ===\n\n";
echo "Make the SECURE path the EASY path!\n";
echo "If ::mine() is easier to type than ::query(), developers will naturally\n";
echo "use the secure method. This makes authorization violations nearly impossible.\n";
