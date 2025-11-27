# Database - PDO Abstraction

## Philosophy

Mini's database layer is a **thin wrapper over PDO** that makes database operations pleasant without hiding the underlying power. We embrace PDO, not abstract it away.

**Key Principles:**
- **PDO at the core** - Full PDO access when you need it
- **Convenience methods** - Common operations made simple
- **Auto-configuration** - UTF-8, timezones, error modes set automatically
- **Zero magic** - Explicit SQL, no query builders or ORMs
- **Transaction support** - Proper transaction depth handling

## Setup

### Default Configuration (SQLite)

By default, Mini auto-creates a SQLite database at `_database.sqlite3`:

```php
// No configuration needed! Just use:
$users = db()->query("SELECT * FROM users");
```

### Custom Database Configuration

Create `_config/PDO.php` to use your own database:

**MySQL:**
```php
<?php
// _config/PDO.php

return new PDO(
    'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    'username',
    'password'
);
```

**PostgreSQL:**
```php
<?php
// _config/PDO.php

return new PDO(
    'pgsql:host=localhost;dbname=myapp',
    'username',
    'password'
);
```

**Environment-based:**
```php
<?php
// _config/PDO.php

$dsn = $_ENV['DATABASE_DSN'] ?? 'sqlite:' . __DIR__ . '/../_database.sqlite3';
$user = $_ENV['DATABASE_USER'] ?? null;
$pass = $_ENV['DATABASE_PASS'] ?? null;

return new PDO($dsn, $user, $pass);
```

## Common Usage Examples

### Basic Queries

```php
// Fetch all rows
$users = db()->query("SELECT * FROM users WHERE active = ?", [1]);

// Fetch single row
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [123]);

// Fetch single value
$count = db()->queryField("SELECT COUNT(*) FROM users");

// Fetch column as array
$ids = db()->queryColumn("SELECT id FROM users WHERE role = ?", ['admin']);
```

### Modifying Data

```php
// Insert
db()->exec(
    "INSERT INTO users (name, email) VALUES (?, ?)",
    ['John Doe', 'john@example.com']
);
$userId = db()->lastInsertId();

// Update
db()->exec(
    "UPDATE users SET active = ? WHERE id = ?",
    [0, 123]
);

// Delete
db()->exec(
    "DELETE FROM users WHERE id = ?",
    [123]
);
```

### Transactions

```php
// Automatic transaction handling
db()->transaction(function() {
    db()->exec("INSERT INTO users (name) VALUES (?)", ['John']);
    db()->exec("INSERT INTO activity_log (user_id, action) VALUES (?, ?)", [123, 'created']);

    // If exception is thrown, transaction is rolled back
    // If function completes, transaction is committed
});

// Nested transactions (only outer transaction commits)
db()->transaction(function() {
    db()->exec("INSERT INTO users ...");

    db()->transaction(function() {
        db()->exec("INSERT INTO activity_log ...");
    });
});
```

### Working with Results

```php
$users = db()->query("SELECT * FROM users");

foreach ($users as $user) {
    echo $user['name']; // Associative arrays by default
}

// Check if table exists
if (db()->tableExists('users')) {
    // Do something
}
```

### Direct PDO Access

When you need PDO-specific features:

```php
$pdo = db()->getPdo();

// Prepared statements
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => 123]);
$user = $stmt->fetch();

// PDO attributes
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 30);

// Transaction control
$pdo->beginTransaction();
try {
    // ... operations
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

## Advanced Examples

### Batch Operations

```php
db()->transaction(function() {
    $stmt = db()->getPdo()->prepare(
        "INSERT INTO users (name, email) VALUES (?, ?)"
    );

    foreach ($users as $user) {
        $stmt->execute([$user['name'], $user['email']]);
    }
});
```

### IN Clause with Parameters

```php
$ids = [1, 2, 3, 4, 5];
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$users = db()->query(
    "SELECT * FROM users WHERE id IN ($placeholders)",
    $ids
);
```

### Complex Query with Joins

```php
$results = db()->query("
    SELECT
        u.id,
        u.name,
        COUNT(p.id) as post_count
    FROM users u
    LEFT JOIN posts p ON p.user_id = u.id
    WHERE u.active = ?
    GROUP BY u.id, u.name
    HAVING post_count > ?
    ORDER BY post_count DESC
", [1, 5]);
```

### Using Query Builders (if you prefer)

Mini doesn't include a query builder, but you can use PDO with any library:

```php
// _config/PDO.php - Share the same PDO instance
return new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
```

```php
// Use with Laravel's query builder (via illuminate/database)
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'mysql',
    'pdo' => db()->getPdo(), // Reuse Mini's PDO
]);

$users = $capsule->table('users')->where('active', 1)->get();
```

## Auto-Configuration

Mini automatically configures PDO with:

- **Error mode:** `PDO::ERRMODE_EXCEPTION`
- **Fetch mode:** `PDO::FETCH_ASSOC`
- **Charset:** UTF-8 (from `php.ini` `default_charset`)
- **Timezone:** From `Mini::$mini->timezone` (MySQL/PostgreSQL)

### Charset Handling

- **MySQL:** Sets `utf8mb4` charset via `SET NAMES`
- **PostgreSQL:** Sets client encoding to UTF-8
- **SQLite:** Sets encoding pragma to UTF-8

### Timezone Handling

- **MySQL:** `SET time_zone = '...'`
- **PostgreSQL:** `SET timezone TO '...'`
- **SQLite:** No timezone support (stores as UTC)

## Configuration

**Config File:** `_config/PDO.php` (optional, defaults to SQLite)

**Environment Variables:**
- `DATABASE_DSN` - Connection string (e.g., `mysql:host=localhost;dbname=app`)
- `DATABASE_USER` - Database username
- `DATABASE_PASS` - Database password

**Mini-prefixed alternatives** (use when avoiding conflicts):
- `MINI_DATABASE_DSN`
- `MINI_DATABASE_USER`
- `MINI_DATABASE_PASS`

## Overriding the Service

### Custom DatabaseInterface Implementation

```php
// _config/mini/Database/DatabaseInterface.php
return new App\Database\CustomDatabase();
```

### Custom PDO Service

```php
// _config/PDO.php
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');

// Apply custom configuration
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 60);
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

// Mini will still apply its standard configuration (charset, timezone, etc.)
return $pdo;
```

### Skip Auto-Configuration

If you don't want Mini's auto-configuration:

```php
// _config/PDO.php
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');

// Configure manually
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

return $pdo;
```

Note: Mini always applies configuration via `PDOService::configure()`. To completely skip this, you'd need to override the DatabaseInterface service entirely.

## Partial Query Builder

Mini includes a lightweight, immutable query builder for **expert-level composition architecture**. This is not a replacement for raw SQL - it's a fundamental building block for managing complexity in object-oriented applications.

### Why Immutability Matters

The primary value of PartialQuery is **architectural composition**:

1. **🏗️ Reusable Query Fragments** - Define base queries once, reuse everywhere without side effects
2. **🔀 Safe Branching** - Branch query logic without mutation or copying
3. **🛡️ Encapsulated Security** - Parameter binding happens at the architectural level
4. **🧩 Expert Tool** - Not a "beginner ORM" but a composition primitive for experienced developers

Secondary benefit: Beginners get IDE autocomplete and safe-by-default SQL injection protection.

### Philosophy

- **Immutable** - Each method returns a NEW instance (no side effects)
- **Composable** - Build reusable, non-mutating query fragments
- **SQL-transparent** - Raw SQL always available via `where()`
- **Safe defaults** - 1000 row limit (industry standard: Google BigTable, cloud DBs)
  - Prevents accidental full table scans and memory exhaustion
  - Forces intentional pagination for large result sets
  - Override with `->limit(PHP_INT_MAX)` if truly needed (rarely appropriate)
- **Iterable** - Use in `foreach` directly
- **Not an ORM** - For complex queries, use `db()->query()` directly

### Basic Usage

```php
// Simple query
$users = db()->table('users')
    ->eq('active', 1)
    ->order('created_at DESC')
    ->limit(50);

foreach ($users as $user) {
    echo $user['name'];
}
```

### Architectural Composition (The Primary Use Case)

The real power is building **reusable, non-mutating query fragments with typed results**:

```php
// Recommended pattern: Model with static query methods
class User {
    private int $id;
    private string $name;
    private string $email;
    private bool $quarantined;
    private ?string $deleted_at;

    // Getters for encapsulation
    public function getName(): string { return $this->name; }
    public function isQuarantined(): bool { return $this->quarantined; }

    /**
     * Base query - returns typed PartialQuery<User>
     * @return PartialQuery<User>
     */
    public static function all(): PartialQuery {
        return db()->table('users')->withHydrator(User::class, false);
    }

    /**
     * @return PartialQuery<User>
     */
    public static function active(): PartialQuery {
        return self::all()->eq('deleted_at', null)->eq('quarantined', 0);
    }

    /**
     * @return PartialQuery<User>
     */
    public static function quarantined(): PartialQuery {
        return self::all()->eq('quarantined', 1);
    }
}

// Alternative: Separate repository class (plural naming)
class Users {
    /** @return PartialQuery<User> */
    public static function all(): PartialQuery {
        return db()->table('users')->withHydrator(User::class, false);
    }

    /** @return PartialQuery<User> */
    public static function quarantined(): PartialQuery {
        return self::all()->eq('quarantined', 1);
    }
}

// Usage - IDE knows types at every step
foreach (User::quarantined() as $user) {  // $user is User
    echo $user->getName();  // IDE autocomplete works!
}

// Further composition without mutation
$recentQuarantined = User::quarantined()
    ->where('created_at > ?', [date('Y-m-d', strtotime('-7 days'))]);

// Type-safe single fetch
$user = User::active()->eq('email', 'john@example.com')->one();  // User|null

// Works with mutations too
db()->update(User::quarantined(), ['notified' => 1]);
db()->delete(User::quarantined()->where('created_at < ?', ['2020-01-01']));

// Original User::active() unchanged - safe to reuse everywhere
foreach (User::active() as $user) {
    // All active users (returns User objects with IDE support)
}
```

**Tip:** Combine with Mini's validation and metadata systems:
- `mini\validator($user::class)->isInvalid($user)` - Validate model instances
- `mini\metadata($user::class)->getDescription()` - Extract model metadata

**Why this matters:**
- **No global state** - Each query is independent
- **No defensive copying** - Immutability guarantees safety
- **Clear encapsulation** - Business rules (soft deletes) live in one place
- **Testable** - Mock PartialQuery instances easily

### WHERE Methods

```php
// Raw SQL (full flexibility)
$query->where('age >= ? AND age <= ?', [18, 67])

// Convenience methods (wrappers around where())
$query->eq('status', 'active')      // status = ?
$query->lt('age', 18)               // age < ?
$query->lte('age', 18)              // age <= ?
$query->gt('age', 67)               // age > ?
$query->gte('age', 67)              // age >= ?
$query->in('status', ['active', 'pending'])  // status IN (?, ?)

// NULL handling (automatic)
$query->eq('deleted_at', null)      // deleted_at IS NULL
```

### SELECT and Ordering

```php
// Select specific columns
$query->select('id', 'name', 'email')

// Ordering (overwrites previous)
$query->order('created_at')                          // Single column (defaults to ASC)
$query->order('created_at ASC')                      // Explicit ASC
$query->order('created_at DESC')                     // Explicit DESC
$query->order('priority DESC, created_at ASC')       // Multi-column
$query->order('FIELD(status, "active", "pending")') // Complex expressions
```

### LIMIT and OFFSET

```php
// Default limit is 1000 (prevents accidents)
$query->limit(100)                  // Fetch 100 rows
$query->offset(50)                  // Skip first 50

// Pagination
$page = 2;
$perPage = 50;
$query->limit($perPage)->offset($page * $perPage)

// Get everything (use with caution)
$query->limit(PHP_INT_MAX)
```

### Execution Methods

```php
// Iterate (uses default limit)
foreach ($query as $row) { }

// Fetch all rows
$rows = $query->fetchAll()

// Fetch single row (uses LIMIT 1 internally)
$user = $query->one()

// Fetch first column from all rows
$ids = $query->select('id')->column()     // [1, 2, 3, ...]
$names = $query->select('name')->column() // ['John', 'Jane', ...]

// Count (ignores LIMIT/OFFSET/SELECT)
$total = $query->count()
```

### Debugging

```php
// var_dump shows SQL and params
var_dump($query);
// Output: ['sql' => 'SELECT * FROM users WHERE ...', 'params' => [1, 'active']]

// String cast shows executable SQL (quoted values)
echo $query;
// Output: SELECT * FROM users WHERE (status = 'active') AND (age >= 18) LIMIT 1000

error_log("Query: " . $query);  // Great for debugging
```

### Multi-tenancy Example

```php
function tenantQuery(string $table): PartialQuery {
    return db()->table($table)->eq('tenant_id', $_SESSION['tenant_id']);
}

$orders = tenantQuery('orders')
    ->gte('total', 100)
    ->order('created_at DESC')
    ->limit(100);
```

### Security Pattern: ::mine() as Authorization Boundary

**RECOMMENDED:** Use `::mine()` as a consistent security boundary across all entity classes to prevent accidental data leaks. This pattern makes authorization violations nearly impossible by making the secure path the default path.

```php
class User {
    private int $id;
    private string $name;
    private string $email;

    public static function query(): PartialQuery {
        return db()->table('users')->withEntityClass(User::class, false);
    }

    /**
     * Returns only users accessible to the current user
     *
     * This is your security boundary - use ::mine() everywhere!
     */
    public static function mine(): PartialQuery {
        $currentUserId = auth()->getUserId();

        // Example: Users can only see themselves and their friends
        return self::query()->where('
            id = ? OR EXISTS (
                SELECT 1 FROM friendships
                WHERE (user_id = ? AND friend_id = users.id)
                   OR (friend_id = ? AND user_id = users.id)
            )
        ', [$currentUserId, $currentUserId, $currentUserId]);
    }

    public static function find(int $id): ?User {
        // IMPORTANT: Use ::mine() not ::query() to enforce authorization
        return self::mine()->eq('id', $id)->one();
    }
}

class Post {
    public static function mine(): PartialQuery {
        $userId = auth()->getUserId();

        // Posts visible to current user (own posts + friends' posts)
        return self::query()->where('
            user_id = ? OR EXISTS (
                SELECT 1 FROM friendships
                WHERE (user_id = ? AND friend_id = posts.user_id)
                   OR (friend_id = ? AND user_id = posts.user_id)
            )
        ', [$userId, $userId, $userId]);
    }
}

class Group {
    public static function mine(): PartialQuery {
        $userId = auth()->getUserId();

        // Groups where user is a member
        return self::query()->where('
            EXISTS (
                SELECT 1 FROM group_members
                WHERE group_id = groups.id AND user_id = ?
            )
        ', [$userId]);
    }
}

// USAGE: Always use ::mine() for user-facing queries
// This makes authorization the DEFAULT - you can't forget it!

// Find specific user (authorized)
$user = User::mine()->eq('id', 123)->one();  // Returns null if not authorized

// List friends
$friends = User::mine()->limit(50);

// Search within authorized scope
$results = User::mine()->where('name LIKE ?', ['%john%']);

// Composable with other filters
$activeFriends = User::mine()->eq('active', 1)->order('name');

// Works with all PartialQuery methods
$count = User::mine()->count();
$names = User::mine()->select('name')->column();

// Authorization enforced on updates/deletes too
db()->update(User::mine()->eq('id', 123), ['bio' => 'New bio']);
db()->delete(Post::mine()->eq('id', 456));
```

**Why this pattern works:**

1. **Secure by default** - `::mine()` is shorter and easier than `::query()`, so developers naturally use it
2. **Hard to bypass** - Authorization is at the query level, not scattered in if-statements
3. **Composable** - `::mine()` returns `PartialQuery`, so all normal methods still work
4. **Consistent** - Same pattern across all entities (User, Post, Group, etc.)
5. **Auditable** - Search codebase for `::query()` to find potential security issues
6. **Testable** - Easy to verify authorization logic in one place per entity

**Common patterns:**

```php
// Public + mine pattern
class Post {
    // Public posts (e.g., homepage feed)
    public static function public(): PartialQuery {
        return self::query()->eq('visibility', 'public');
    }

    // Posts accessible to current user (includes private posts from friends)
    public static function mine(): PartialQuery {
        if (!auth()->check()) {
            return self::public();  // Not logged in = public only
        }

        $userId = auth()->getUserId();
        return self::query()->where('
            visibility = "public" OR user_id = ? OR EXISTS (...)
        ', [$userId]);
    }
}

// Admin override pattern
class User {
    public static function mine(): PartialQuery {
        if (auth()->isAdmin()) {
            return self::query();  // Admins see everyone
        }

        return self::query()->where('...');  // Regular users: filtered
    }
}

// Tenant isolation pattern
class Order {
    public static function mine(): PartialQuery {
        return self::query()->eq('tenant_id', auth()->getTenantId());
    }
}
```

**Anti-pattern to avoid:**

```php
// DON'T: Manual authorization checks scattered everywhere
$user = User::query()->eq('id', 123)->one();
if ($user->id !== auth()->getUserId() && !isFriend($user)) {
    throw new ForbiddenException();
}

// DO: Authorization at query level
$user = User::mine()->eq('id', 123)->one();
if (!$user) {
    throw new NotFoundException();  // Handles both "not found" and "not authorized"
}
```

### Complex Queries

For complex queries (joins, subqueries, CTEs), use raw SQL:

```php
// Query builder is for simple queries
$simple = db()->table('users')
    ->eq('active', 1)
    ->order('created_at DESC');

// Complex queries use db()->query() directly
$complex = db()->query("
    SELECT
        u.id,
        u.name,
        COUNT(p.id) as post_count,
        AVG(p.score) as avg_score
    FROM users u
    LEFT JOIN posts p ON p.user_id = u.id
    WHERE u.active = ?
    GROUP BY u.id, u.name
    HAVING post_count > ?
    ORDER BY avg_score DESC
", [1, 5]);
```

### Immutability Example

```php
$baseQuery = db()->table('users')->eq('active', 1);

// Each refinement creates a new instance
$admins = $baseQuery->eq('role', 'admin');
$moderators = $baseQuery->eq('role', 'moderator');

// $baseQuery is unchanged
foreach ($baseQuery as $user) {
    // All active users
}

foreach ($admins as $admin) {
    // Only active admins
}
```

### Why Not Just Use Raw SQL?

**Traditional approach (mutable, error-prone):**
```php
function getUsers($includeDeleted = false, $role = null, $verified = null) {
    $sql = "SELECT * FROM users WHERE 1=1";
    $params = [];

    if (!$includeDeleted) {
        $sql .= " AND is_deleted = ?";
        $params[] = 0;
    }

    if ($role) {
        $sql .= " AND role = ?";
        $params[] = $role;
    }

    if ($verified !== null) {
        $sql .= " AND email_verified = ?";
        $params[] = $verified;
    }

    return db()->query($sql, $params);
}
```

**Problems:**
- String concatenation is error-prone
- Must carefully manage parameter array indices
- Can't reuse partial queries
- Testing requires mocking database calls

**PartialQuery approach (immutable, composable):**
```php
class User {
    public static function query(): PartialQuery {
        return db()->table('users');
    }

    public static function active(): PartialQuery {
        return self::query()->eq('is_deleted', 0);
    }
}

// Compose without mutation
$query = User::active();
if ($role) $query = $query->eq('role', $role);
if ($verified !== null) $query = $query->eq('email_verified', $verified);

return $query->fetchAll();
```

**Benefits:**
- No string manipulation
- Parameters bound automatically
- Each step is reusable and testable
- Immutability prevents bugs

### DELETE and UPDATE with PartialQuery

PartialQuery can be used with `db()->delete()` and `db()->update()` for composable mutations:

```php
// Delete using composable scopes
class User {
    public static function spam(): PartialQuery {
        return db()->table('users')->eq('status', 'spam');
    }

    public static function inactive(): PartialQuery {
        return db()->table('users')->eq('status', 'inactive');
    }
}

// Delete spam users
$deleted = db()->delete(User::spam());

// Delete old inactive accounts
$deleted = db()->delete(
    User::inactive()->where('last_login < ?', [date('Y-m-d', strtotime('-1 year'))])
);

// Update with array (simple assignments)
$updated = db()->update(
    User::inactive(),
    ['status' => 'archived', 'archived_at' => date('Y-m-d H:i:s')]
);

// Update with raw SQL expression
$updated = db()->update(
    db()->table('users')->eq('status', 'active'),
    'login_count = login_count + 1'
);
```

**Important notes:**
- WHERE clauses and LIMIT are respected
- SELECT, ORDER BY, and OFFSET are ignored
- Values in arrays are **not converted** - you must handle conversion:
  - Dates: `date('Y-m-d H:i:s')`
  - JSON: `json_encode($data)`
  - Objects: Extract IDs or serialize manually

**Safety:**
The 1000 row default limit protects against accidental mass deletes/updates:
```php
// Safe - only affects 1000 rows max
db()->delete(db()->table('users')->eq('status', 'spam'));

// To delete everything, explicitly set limit
db()->delete(db()->table('users')->eq('status', 'spam')->limit(PHP_INT_MAX));
```

### Object Hydration

PartialQuery supports automatic conversion of database rows into typed objects. This enables type-safe model classes while preserving composability:

```php
class User {
    public int $id;
    public string $name;
    public string $email;
    public int $age;

    public function greet(): string {
        return "Hello, I'm {$this->name}!";
    }
}

// Return User objects instead of arrays
$users = db()->table('users')->withEntityClass(User::class);
foreach ($users as $user) {
    echo $user->greet();  // Type-safe!
}
```

**Composable scopes with hydration and type hints:**
```php
class UserScope {
    /**
     * @return PartialQuery<User>
     */
    public static function all($db): PartialQuery {
        return $db->table('users')->withEntityClass(User::class);
    }

    /**
     * @return PartialQuery<User>
     */
    public static function adults($db): PartialQuery {
        return self::all($db)->gte('age', 18);
    }
}

// Type-safe iteration - IDE knows $user is User object
foreach (UserScope::adults($db) as $user) {
    echo $user->name;  // IDE autocomplete works!
}

// one() returns User|null
$user = UserScope::adults($db)->one();  // Type: User|null

// Hydration doesn't prevent mutations
db()->update(UserScope::adults($db), 'days_active = days_active + 1');
```

**Hydration with withEntityClass():**

Use `withEntityClass()` when you want the framework to handle object construction and property hydration automatically:

```php
class User {
    private int $id;           // Reflection sets private properties
    protected string $name;    // Works with protected properties
    public string $email;      // And public properties

    public function __construct(private PDO $db) {
        // Constructor called FIRST with args, properties set AFTER
    }

    public function getName(): string { return $this->name; }
}

// With constructor arguments
$users = db()->table('users')->withEntityClass(User::class, [db()->getPdo()]);

// Skip constructor (pass false) - useful when constructor has required params
$users = db()->table('users')->withEntityClass(User::class, false);

// Reflection properties are cached per iteration for efficiency (thread-safe)
foreach ($users as $user) {
    echo $user->getName();  // Private/protected properties hydrated correctly
}
```

**Custom hydration with withHydrator():**

Use `withHydrator()` when you need full control over object construction:

```php
$users = db()->table('users')->withHydrator(
    fn($id, $name, $email, $age) => new User($id, $name, $email, $age)
);
```

**Important:**
- `select()` clears entity class/hydrator (partial columns can't populate full objects)
- `one()` respects hydration and returns a single object
- Hydration works with all query composition methods

### Relationships with ActiveRecordTrait

PartialQuery enables composable relationships by accepting any SELECT query as its base. Combined with `ActiveRecordTrait`, you can build expressive model classes with type-safe relationship methods.

**Blog Example Schema:**
```sql
CREATE TABLE authors (
    id INTEGER PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255),
    bio TEXT,
    created_at DATETIME
);

CREATE TABLE posts (
    id INTEGER PRIMARY KEY,
    author_id INTEGER REFERENCES authors(id),
    title VARCHAR(255),
    body TEXT,
    published_at DATETIME,
    created_at DATETIME
);

CREATE TABLE comments (
    id INTEGER PRIMARY KEY,
    post_id INTEGER REFERENCES posts(id),
    author_id INTEGER REFERENCES authors(id),
    body TEXT,
    created_at DATETIME
);

CREATE TABLE friendships (
    user_id INTEGER REFERENCES authors(id),
    friend_id INTEGER REFERENCES authors(id),
    created_at DATETIME,
    PRIMARY KEY (user_id, friend_id)
);
```

**Author Model with Relationships:**
```php
<?php
use mini\Database\{ActiveRecordTrait, PartialQuery};

class Author
{
    use ActiveRecordTrait;

    public int $id;
    public string $name;
    public string $email;
    public ?string $bio;
    public string $created_at;

    /**
     * Get all posts by this author
     * @return PartialQuery<Post>
     */
    public function getPosts(): PartialQuery
    {
        return Post::query()->eq('author_id', $this->id);
    }

    /**
     * Get published posts only
     * @return PartialQuery<Post>
     */
    public function getPublishedPosts(): PartialQuery
    {
        return $this->getPosts()->where('published_at IS NOT NULL');
    }

    /**
     * Get friends of this author (bidirectional friendship)
     * Uses JOIN to find all users connected via friendships table
     * @return PartialQuery<Author>
     */
    public function getFriends(): PartialQuery
    {
        return (new PartialQuery(db(), '
            SELECT a.* FROM authors a
            INNER JOIN friendships f ON (
                (f.friend_id = a.id AND f.user_id = ?)
                OR (f.user_id = a.id AND f.friend_id = ?)
            )
        ', [$this->id, $this->id]))->withEntityClass(Author::class, false);
    }

    /**
     * Get authors this user is following (one-way)
     * @return PartialQuery<Author>
     */
    public function getFollowing(): PartialQuery
    {
        return (new PartialQuery(db(), '
            SELECT a.* FROM authors a
            INNER JOIN friendships f ON f.friend_id = a.id AND f.user_id = ?
        ', [$this->id]))->withEntityClass(Author::class, false);
    }

    /**
     * Get authors following this user (one-way)
     * @return PartialQuery<Author>
     */
    public function getFollowers(): PartialQuery
    {
        return (new PartialQuery(db(), '
            SELECT a.* FROM authors a
            INNER JOIN friendships f ON f.user_id = a.id AND f.friend_id = ?
        ', [$this->id]))->withEntityClass(Author::class, false);
    }

    /**
     * Get all comments by this author across all posts
     * @return PartialQuery<Comment>
     */
    public function getComments(): PartialQuery
    {
        return Comment::query()->eq('author_id', $this->id);
    }

    /**
     * Get feed: posts from friends, ordered by date
     * @return PartialQuery<Post>
     */
    public function getFeed(): PartialQuery
    {
        return (new PartialQuery(db(), '
            SELECT p.* FROM posts p
            INNER JOIN friendships f ON (
                (f.friend_id = p.author_id AND f.user_id = ?)
                OR (f.user_id = p.author_id AND f.friend_id = ?)
            )
        ', [$this->id, $this->id]))
            ->withEntityClass(Post::class, false)
            ->where('p.published_at IS NOT NULL')
            ->order('p.published_at DESC');
    }
}
```

**Post Model with Relationships:**
```php
<?php
use mini\Database\{ActiveRecordTrait, PartialQuery};

class Post
{
    use ActiveRecordTrait;

    public int $id;
    public int $author_id;
    public string $title;
    public string $body;
    public ?string $published_at;
    public string $created_at;

    /**
     * Get the author of this post
     */
    public function getAuthor(): ?Author
    {
        return Author::find($this->author_id);
    }

    /**
     * Get all comments on this post
     * @return PartialQuery<Comment>
     */
    public function getComments(): PartialQuery
    {
        return Comment::query()->eq('post_id', $this->id);
    }

    /**
     * Get recent comments (last 7 days)
     * @return PartialQuery<Comment>
     */
    public function getRecentComments(): PartialQuery
    {
        return $this->getComments()
            ->where('created_at > ?', [date('Y-m-d', strtotime('-7 days'))])
            ->order('created_at DESC');
    }

    /**
     * Get comments with author info via JOIN
     * Returns arrays with both comment and author data
     */
    public function getCommentsWithAuthors(): PartialQuery
    {
        return new PartialQuery(db(), '
            SELECT c.*, a.name as author_name, a.email as author_email
            FROM comments c
            INNER JOIN authors a ON a.id = c.author_id
        ', []);
    }

    /**
     * Published posts scope
     * @return PartialQuery<Post>
     */
    public static function published(): PartialQuery
    {
        return self::query()->where('published_at IS NOT NULL');
    }

    /**
     * Drafts scope
     * @return PartialQuery<Post>
     */
    public static function drafts(): PartialQuery
    {
        return self::query()->eq('published_at', null);
    }
}
```

**Comment Model with Relationships:**
```php
<?php
use mini\Database\{ActiveRecordTrait, PartialQuery};

class Comment
{
    use ActiveRecordTrait;

    public int $id;
    public int $post_id;
    public int $author_id;
    public string $body;
    public string $created_at;

    /**
     * Get the post this comment belongs to
     */
    public function getPost(): ?Post
    {
        return Post::find($this->post_id);
    }

    /**
     * Get the author of this comment
     */
    public function getAuthor(): ?Author
    {
        return Author::find($this->author_id);
    }

    /**
     * Get other comments on the same post
     * @return PartialQuery<Comment>
     */
    public function getSiblingComments(): PartialQuery
    {
        return Comment::query()
            ->eq('post_id', $this->post_id)
            ->where('id != ?', [$this->id]);
    }
}
```

**Usage Examples:**

```php
// Find an author and explore relationships
$author = Author::find(512);

// Get friends - returns PartialQuery, composable
$friends = $author->getFriends();
echo "Total friends: " . $friends->count();

// Filter friends further
$activeFriends = $author->getFriends()
    ->where('created_at > ?', [date('Y-m-d', strtotime('-1 year'))])
    ->order('name')
    ->limit(10);

foreach ($activeFriends as $friend) {
    echo $friend->name . "\n";
}

// Chain through relationships
$post = $author->getPosts()->one();
if ($post) {
    $comments = $post->getComments();
    echo "Post '{$post->title}' has {$comments->count()} comments\n";

    // Get recent comments
    foreach ($post->getRecentComments()->limit(5) as $comment) {
        echo "- " . $comment->body . "\n";
    }
}

// Get author's feed (posts from friends)
foreach ($author->getFeed()->limit(20) as $post) {
    echo "{$post->title} by author #{$post->author_id}\n";
}

// Navigate: Author -> Post -> Comments
$firstPost = Author::find(1)->getPosts()->one();
$firstComment = $firstPost?->getComments()->one();
$commentAuthor = $firstComment?->getAuthor();

// Aggregate queries still work
$totalComments = $author->getComments()->count();
$recentPostCount = $author->getPublishedPosts()
    ->where('published_at > ?', [date('Y-m-d', strtotime('-30 days'))])
    ->count();

// Get all post IDs by this author
$postIds = $author->getPosts()->select('id')->column();

// Complex: Get friends who have commented on my posts
$friendsWhoCommented = (new PartialQuery(db(), '
    SELECT DISTINCT a.* FROM authors a
    INNER JOIN friendships f ON (
        (f.friend_id = a.id AND f.user_id = ?)
        OR (f.user_id = a.id AND f.friend_id = ?)
    )
    INNER JOIN comments c ON c.author_id = a.id
    INNER JOIN posts p ON p.id = c.post_id AND p.author_id = ?
', [$author->id, $author->id, $author->id]))
    ->withEntityClass(Author::class, false);

foreach ($friendsWhoCommented as $friend) {
    echo "{$friend->name} commented on your posts\n";
}
```

**Key Patterns:**

1. **Simple foreign key (belongs-to):** Return single entity via `::find()`
   ```php
   public function getAuthor(): ?Author {
       return Author::find($this->author_id);
   }
   ```

2. **Has-many:** Return PartialQuery filtered by foreign key
   ```php
   public function getPosts(): PartialQuery {
       return Post::query()->eq('author_id', $this->id);
   }
   ```

3. **Many-to-many via JOIN:** Use `new PartialQuery()` with JOIN SQL
   ```php
   public function getFriends(): PartialQuery {
       return (new PartialQuery(db(), '
           SELECT a.* FROM authors a
           INNER JOIN friendships f ON f.friend_id = a.id AND f.user_id = ?
       ', [$this->id]))->withEntityClass(Author::class, false);
   }
   ```

4. **Composable chains:** All relationship methods return PartialQuery
   ```php
   $author->getFriends()->eq('active', 1)->order('name')->limit(10);
   ```

5. **Eager-load alternative:** Use JOIN to fetch related data in one query
   ```php
   public function getCommentsWithAuthors(): PartialQuery {
       return new PartialQuery(db(), '
           SELECT c.*, a.name as author_name FROM comments c
           INNER JOIN authors a ON a.id = c.author_id
       ', []);
   }
   ```

### SQL Dialect Support

PartialQuery automatically generates database-specific SQL by detecting the dialect from your database connection. This ensures correct LIMIT/OFFSET syntax across different databases.

**Supported Dialects:**
- **MySQL** - `LIMIT offset, count` (non-standard MySQL syntax)
- **PostgreSQL** - `LIMIT count OFFSET offset` (SQL standard)
- **SQLite** - `LIMIT count OFFSET offset` (SQL standard)
- **SQL Server** - `OFFSET offset ROWS FETCH NEXT count ROWS ONLY`
- **Oracle** - `OFFSET offset ROWS FETCH NEXT count ROWS ONLY` (modern syntax)
- **Generic** - `LIMIT count OFFSET offset` (ANSI SQL fallback for unknown databases)

**How it works:**

```php
// DatabaseInterface::getDialect() detects dialect automatically
$db = db();
$dialect = $db->getDialect();  // SqlDialect::MySQL, SqlDialect::Postgres, etc.

// PartialQuery uses dialect to generate correct SQL
$users = db()->table('users')->limit(10)->offset(20);

// MySQL generates:    LIMIT 20, 10
// Postgres generates: LIMIT 10 OFFSET 20
// SQL Server:         OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY
```

**You don't need to do anything** - dialect detection happens automatically based on your PDO connection. The same PartialQuery code works correctly across all database backends.

**Custom Implementation:**

If you implement `DatabaseInterface` yourself, just return the appropriate dialect:

```php
class MyDatabase implements DatabaseInterface {
    public function getDialect(): SqlDialect {
        return SqlDialect::Postgres;
    }
}
```

### Best Practices

**DO:**
- Use for dynamic queries with conditional filters
- Build reusable scopes in model classes for business logic
- Compose queries from reusable fragments
- Use `where()` with raw SQL for complex conditions
- Rely on the 1000 row default limit as safety
- Use composable DELETE/UPDATE for batch operations on filtered data

**DON'T:**
- Don't use for complex joins/subqueries (use `db()->query()` instead)
- Don't build JOIN clauses (PartialQuery is single-table focused)
- Don't forget immutability returns NEW instances
- Don't use for one-off simple queries (just use `db()->query()`)
- Don't assume automatic value conversion in `update()` - convert manually

## VirtualDatabase - SQL Interface to Non-SQL Data

`VirtualDatabase` provides a SQL interface to non-SQL data sources (CSV files, JSON, REST APIs, generators). This allows you to query any data source using familiar SQL syntax while maintaining the ability to optimize backend calls.

**Quick example:**
```php
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\{VirtualTable, Row, CsvTable};

$vdb = new VirtualDatabase();

// Register a CSV file as a table
$vdb->registerTable('countries', CsvTable::fromFile('data/countries.csv'));

// Query it with SQL
foreach ($vdb->query("SELECT * FROM countries WHERE continent = ?", ['Europe']) as $row) {
    echo $row['name'];
}
```

**Key features:**
- Smart execution (streaming vs materialization based on ORDER BY)
- Collation support (BINARY, NOCASE, locale-specific)
- Full WHERE/ORDER BY/LIMIT evaluation
- DML support (INSERT/UPDATE/DELETE)
- Backend optimization hints via OrderInfo

See **[Virtual/README.md](Virtual/README.md)** for complete documentation.

## Database Scope

Database connections are **scoped per request**:
- In traditional PHP: One connection per page load
- In long-running apps (Swoole/RoadRunner): Fresh connection per request

This ensures:
- No connection sharing between requests
- Automatic cleanup via garbage collection
- Safe for concurrent requests
