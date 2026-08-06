# Database

Mini's database layer wraps a database engine (PDO by default) with an immutable query builder. It is a generic, SQL-first building block for a forkable core framework: plain SQL in, composable queries out — no ORM magic to inherit or work around.

## Quick Start

```php
// Raw queries - returns a composable Query; rows are stdClass objects
foreach (db()->query("SELECT * FROM users WHERE active = ?", [1]) as $row) {
    echo $row->name;
}

// Convenience methods
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = db()->queryField("SELECT COUNT(*) FROM users");
$ids = db()->queryColumn("SELECT id FROM users WHERE role = ?", ['admin']);

// Mutations
db()->exec("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'john@example.com']);
db()->exec("UPDATE users SET active = ? WHERE id = ?", [0, 123]);
db()->exec("DELETE FROM users WHERE id = ?", [123]);

// Structured writes
$userId = db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']); // returns insert id
db()->upsert('users', ['email' => 'john@example.com', 'name' => 'John'], 'email');  // insert-or-update

// Last insert ID
$userId = db()->lastInsertId();

// Transactions (auto rollback on exception, nested transactions throw)
db()->transaction(function() {
    db()->exec("INSERT INTO users ...");
    db()->exec("INSERT INTO activity_log ...");
});
```

## Query: Composable Queries

`db()->query()` returns a `mini\Database\Query` — a read-focused facade over the immutable `PartialQuery` builder. Each method returns a NEW instance, making queries safe to reuse and compose. Mutations go through `db()->update()` / `db()->delete()`, not the query itself.

```php
// Basic usage - iterate directly
foreach (db()->query('SELECT * FROM users')->eq('active', 1) as $user) {
    echo $user->name;
}

// Composition - original unchanged
$active = db()->query('SELECT * FROM users')->eq('active', 1);
$admins = $active->eq('role', 'admin');      // New instance
$mods = $active->eq('role', 'moderator');    // New instance

// Filtering - all conditions are combined with AND
$query->eq('column', $value)      // = (NULL becomes IS NULL)
$query->lt('column', $value)      // <
$query->lte('column', $value)     // <=
$query->gt('column', $value)      // >
$query->gte('column', $value)     // >=
$query->like('column', $pattern)  // LIKE
$query->in('column', [...])       // IN (...)
$query->in('column', $query2)     // IN (SELECT ...) real SQL subquery
$query->or($p1, $p2, ...)         // OR-combined predicates
$query->where('sql', $params)     // Raw WHERE clause (ANDed with existing)

// Shaping
$query->select('id, name')        // Replace SELECT column list
$query->columns('id', 'name')     // Restrict to named columns
$query->order('created_at DESC')  // ORDER BY (replaces previous)
$query->orderBy('name', true)     // ORDER BY one column (asc/desc flag)
$query->distinct()                // SELECT DISTINCT
$query->limit(100)                // LIMIT (can only narrow - see below)
$query->offset(50)                // OFFSET
$query->withCTE('name', $query2)  // WITH name AS (...)

// Execution
foreach ($query as $row) { }      // Iterate (streaming)
$row = $query->one();             // First row or null
$row = $query->first();           // First row or RuntimeException
$rows = $query->all();            // All rows as array
$total = $query->count();         // Row count (respects LIMIT/OFFSET)
$ids = $query->column();          // First column as array
$value = $query->field();         // First column of first row
$bool = $query->exists();         // Any rows?
$row = $query->load($id);         // Single row by primary key
```

### Limits can only narrow

A `Query` represents a *window* into data. `limit()` can shrink the window but never expand it, and `offset()` stays inside it:

```php
$q->limit(10)->limit(5);   // limit becomes 5 (shrink OK)
$q->limit(10)->limit(20);  // limit stays 10 (can't expand)
$q->limit(10)->offset(5);  // LIMIT 5 OFFSET 5 (still within the original 10)
```

This is what makes a query safe to hand to less-trusted code (e.g. a template): downstream code can only narrow the result set, never widen it.

**For pagination**, prefer index-based cursors over OFFSET:

```php
// Good: cursor-based (efficient at any page)
$posts = Post::query()
    ->gt('id', $lastSeenId)
    ->order('id')
    ->limit(50);

// Avoid: offset-based (slow on deep pages)
$posts = Post::query()
    ->order('id')
    ->offset(10000)
    ->limit(50);
```

## Model Pattern

Define query methods on your model class. This is explicit, type-safe, and doesn't rely on magic methods.

> Mini also ships `mini\Database\Model`, an Active Record base class with attribute-mapped tables (`#[Table]`, `#[PrimaryKey]`), auth-checked `save()`/`delete()` and row-scoped `updatable()`/`deletable()`. The plain-class pattern below is the zero-inheritance alternative; both build on the same `Query` API.

```php
class User
{
    public int $id;
    public string $name;
    public string $email;
    public bool $active;
    public ?string $deleted_at;

    /**
     * Base query with hydration
     * @return Query
     */
    public static function query(): Query
    {
        return db()->query('SELECT * FROM users')->withEntityClass(self::class, false);
    }

    public static function active(): Query
    {
        return self::query()->eq('active', 1)->eq('deleted_at', null);
    }

    public static function admins(): Query
    {
        return self::active()->eq('role', 'admin');
    }

    public static function find(int $id): ?User
    {
        return self::query()->eq('id', $id)->one();
    }
}

// Usage
foreach (User::admins()->order('name')->limit(10) as $user) {
    echo $user->name;  // IDE autocomplete works
}

$user = User::find(123);
$count = User::active()->count();
```

### Hydration with `withEntityClass()`

The second parameter controls instantiation:

```php
->withEntityClass(User::class, false)  // Skip constructor (default)
->withEntityClass(User::class, [])     // Call constructor with no args
->withEntityClass(User::class, [$arg]) // Call constructor with args
```

### Automatic Type Conversion

Hydration automatically converts database values to PHP types using the converter registry. Built-in conversions:

```php
class Post
{
    public int $id;                           // INTEGER → int (PDO native)
    public string $title;                     // TEXT → string (PDO native)
    public ?string $summary;                  // NULL preserved
    public bool $published;                   // 0/1/"0"/"1"/"" → bool
    public \DateTimeImmutable $created_at;    // See datetime formats below
    public \DateTime $updated_at;             // See datetime formats below
    public Status $status;                    // TEXT/INTEGER → BackedEnum (auto)
}

enum Status: string {
    case Draft = 'draft';
    case Published = 'published';
}
```

**DateTime conversion** supports multiple formats:
- **String**: `"2024-01-15 10:30:00"` - interpreted in `sqlTimezone`
- **Integer (seconds)**: `1705315800` - Unix timestamp (always UTC)
- **Integer (milliseconds)**: `1705315800123` - auto-detected when >= 100 billion
- **Float**: `1705315800.123456` - seconds with microsecond precision

**Timezone behavior**: String dates from the database are interpreted in `Mini::$mini->sqlTimezone` (defaults to `'+00:00'` UTC) and automatically converted to the application timezone. Configure via `SQL_TIMEZONE` or `MINI_SQL_TIMEZONE` environment variable using offset format (e.g., `'+00:00'`, `'-05:00'`).

For SQL Server (which cannot set session timezone), Mini verifies the server's timezone matches `sqlTimezone` and throws if it doesn't.

### Custom Row Hydration with Hydration Interface

For complex hydration/dehydration (computed properties, column renaming, nested objects), implement `Hydration`:

```php
use mini\Database\Hydration;

class User implements Hydration
{
    public int $id;
    public string $fullName;
    public \DateTimeImmutable $createdAt;

    public static function fromSqlRow(object $row): static
    {
        $user = new static();
        $user->id = $row->id;
        $user->fullName = $row->first_name . ' ' . $row->last_name;
        $user->createdAt = new \DateTimeImmutable($row->created_at);
        return $user;
    }

    public function toSqlRow(): array
    {
        $parts = explode(' ', $this->fullName, 2);
        return [
            'id' => $this->id,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}

// Hydration uses fromSqlRow() automatically when reading
$users = User::query()->limit(10);

// Dehydration uses toSqlRow() automatically when the entity is written
// through the framework (e.g. mini\Database\Model::save(), which runs
// entities through the Dehydrator). For plain classes, call it yourself:
db()->insert('users', $user->toSqlRow());
```

### Custom Value Objects with SqlValueHydrator

For value objects that map to a single column, implement `SqlValueHydrator`:

```php
use mini\Database\SqlValue;
use mini\Database\SqlValueHydrator;

class Money implements SqlValue, SqlValueHydrator
{
    public function __construct(public readonly int $cents) {}

    // SQL column → PHP (hydration)
    public static function fromSqlValue(string|int|float|bool $value): static
    {
        return new static((int) $value);
    }

    // PHP → SQL column (queries)
    public function toSqlValue(): int
    {
        return $this->cents;
    }
}

// Now works automatically in entities
class Order {
    public int $id;
    public Money $total;  // Hydrated from INTEGER column
}
```

### Custom Converters

For types you don't control, register a converter:

```php
// In bootstrap.php
$registry = Mini::$mini->get(ConverterRegistryInterface::class);

// sql-value → SomeLibraryType
$registry->register(
    fn(string $v): SomeType => SomeType::parse($v),
    null,        // target: infer from return type
    'sql-value'  // source: database values
);
```

For types without registered converters, raw PDO values are assigned directly.

## Relationships

Define relationship methods explicitly. No magic, no autowiring - just clear, predictable code you write once.

```php
class User
{
    public int $id;
    public string $name;

    public static function query(): Query
    {
        return db()->query('SELECT * FROM users')->withEntityClass(self::class, false);
    }

    /**
     * Posts by this user
     */
    public function posts(): Query
    {
        return Post::query()->eq('user_id', $this->id);
    }

    /**
     * Published posts only
     */
    public function publishedPosts(): Query
    {
        return $this->posts()->where('published_at IS NOT NULL');
    }

    /**
     * Friends (many-to-many via friendships table)
     */
    public function friends(): Query
    {
        return db()->query('
            SELECT u.* FROM users u
            INNER JOIN friendships f ON (
                (f.friend_id = u.id AND f.user_id = ?)
                OR (f.user_id = u.id AND f.friend_id = ?)
            )
        ', [$this->id, $this->id])
            ->withEntityClass(self::class, false);
    }
}

class Post
{
    public int $id;
    public int $user_id;
    public string $title;
    public ?string $published_at;

    public static function query(): Query
    {
        return db()->query('SELECT * FROM posts')->withEntityClass(self::class, false);
    }

    /** Get the author */
    public function author(): ?User
    {
        return User::find($this->user_id);
    }

    public function comments(): Query
    {
        return Comment::query()->eq('post_id', $this->id);
    }

    public static function published(): Query
    {
        return self::query()->where('published_at IS NOT NULL');
    }
}

// Usage
$user = User::find(1);

foreach ($user->posts()->order('created_at DESC')->limit(10) as $post) {
    echo $post->title;
}

foreach ($user->friends()->eq('active', 1) as $friend) {
    echo $friend->name;
}

$post = Post::published()->order('published_at DESC')->one();
$author = $post?->author();
$commentCount = $post?->comments()->count();
```

**Why explicit methods instead of magic?**

- **Type safety** - IDE knows return types, autocomplete works
- **No surprises** - No `__get`/`__call` magic that may be deprecated
- **Discoverable** - Methods appear in IDE, easy to find and understand
- **Customizable** - Add filtering, ordering, or joins as needed
- **Write once** - You define each relationship once, use it everywhere

## Row-Level Access Control

Define scoped query methods that embed authorization rules. The WHERE clause *is* the authorization - no separate permission checks needed.

```php
class User
{
    public static function query(): Query
    {
        return db()->query('SELECT * FROM users')->withEntityClass(self::class, false);
    }

    /** Users the current user can read */
    public static function readable(): Query
    {
        return self::query()->eq('organization_id', Auth::orgId());
    }

    /** Users the current user can update */
    public static function updatable(): Query
    {
        return self::readable()->where('role != ?', ['admin']); // Can't edit admins
    }

    /** Users the current user can delete */
    public static function deletable(): Query
    {
        return self::updatable()->where('id != ?', [Auth::userId()]); // Can't delete self
    }

    public static function find(int $id): ?User
    {
        return self::readable()->eq('id', $id)->one();
    }
}

// Read - returns null if not authorized
$user = User::find(123);

// Update - returns 0 rows affected if not authorized
db()->update(User::updatable()->eq('id', 123), ['name' => 'Frode']);

// Delete - returns 0 rows affected if not authorized
db()->delete(User::deletable()->eq('id', 456));
```

Authorization failures are silent (0 rows affected) rather than throwing exceptions. This makes the pattern simple to use and test.

### Simple `::mine()` Pattern

For simpler cases, use `::mine()` as a single security boundary:

```php
class Post
{
    public static function query(): Query
    {
        return db()->query('SELECT * FROM posts')->withEntityClass(self::class, false);
    }

    /** Posts accessible to current user */
    public static function mine(): Query
    {
        return self::query()->where('user_id = ? OR visibility = ?', [Auth::userId(), 'public']);
    }

    public static function find(int $id): ?Post
    {
        return self::mine()->eq('id', $id)->one();
    }
}

// User-facing queries use ::mine()
$posts = Post::mine()->order('created_at DESC')->limit(20);
$post = Post::find(123);  // Returns null if not authorized

// Admin/internal queries bypass with ::query()
$allPosts = Post::query()->eq('status', 'spam');  // For moderation
```

## DELETE and UPDATE

```php
// Delete with composable scopes
db()->delete(User::query()->eq('status', 'spam'));

// Update with array
db()->update(
    User::query()->eq('status', 'inactive'),
    ['status' => 'archived', 'archived_at' => date('Y-m-d H:i:s')]
);

// Update with raw SQL
db()->update(
    User::query()->eq('status', 'active'),
    'login_count = login_count + 1'
);
```

Subqueries work with DELETE and UPDATE:

```php
// Delete users who have no posts
$usersWithPosts = Post::query()->select('user_id');
db()->delete(User::query()->in('id', $usersWithPosts));

// Update users who ordered a specific product
$buyers = Order::query()->eq('product_id', 123)->select('user_id');
db()->update(User::query()->in('id', $buyers), ['vip' => 1]);
```

**Note:** Subqueries in `in()` require an explicit `->select('column')` to specify which column to match.

## Configuration

By default, Mini uses SQLite at `_database.sqlite3` in the project root. Configure another database via environment variable (`DATABASE_URL` or `MINI_DATABASE_URL`, e.g. `mysql://user:pass@host/dbname`), or via a config file:

```php
// _config/PDO.php
return new PDO(
    'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    'username',
    'password'
);
```

Mini auto-configures charset, timezone, error mode (exceptions), and fetch mode.

## Direct PDO Access

When the default PDO backend is in use, the concrete `PDODatabase` exposes the connection:

```php
$pdo = db()->getPdo();  // PDODatabase only - not part of DatabaseInterface
$stmt = $pdo->prepare("...");
```
