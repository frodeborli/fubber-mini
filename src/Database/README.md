# Database

Mini's database layer wraps a database engine (PDO by default) with an immutable query builder.

## Quick Start

```php
// Raw queries - returns iterable
foreach (db()->query("SELECT * FROM users WHERE active = ?", [1]) as $row) {
    echo $row['name'];
}

// Convenience methods
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = db()->queryField("SELECT COUNT(*) FROM users");
$ids = db()->queryColumn("SELECT id FROM users WHERE role = ?", ['admin']);

// Mutations
db()->exec("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'john@example.com']);
db()->exec("UPDATE users SET active = ? WHERE id = ?", [0, 123]);
db()->exec("DELETE FROM users WHERE id = ?", [123]);

// Last insert ID
$userId = db()->lastInsertId();

// Transactions (auto rollback on exception, nested transactions throw)
db()->transaction(function() {
    db()->exec("INSERT INTO users ...");
    db()->exec("INSERT INTO activity_log ...");
});
```

## PartialQuery: Composable Queries

PartialQuery is an immutable query builder. Each method returns a NEW instance, making queries safe to reuse and compose.

```php
// Basic usage - iterate directly
foreach (db()->partialQuery('users')->eq('active', 1) as $user) {
    echo $user['name'];
}

// Composition - original unchanged
$active = db()->partialQuery('users')->eq('active', 1);
$admins = $active->eq('role', 'admin');      // New instance
$mods = $active->eq('role', 'moderator');    // New instance

// Methods - all conditions are combined with AND
$query->eq('column', $value)      // = (NULL becomes IS NULL)
$query->lt('column', $value)      // <
$query->lte('column', $value)     // <=
$query->gt('column', $value)      // >
$query->gte('column', $value)     // >=
$query->in('column', [...])       // IN (...)
$query->in('column', $subquery)   // IN (SELECT ...) via CTE
$query->where('sql', $params)     // Raw WHERE clause (ANDed with existing)
$query->order('created_at DESC')  // ORDER BY (replaces previous)
$query->limit(100)                // LIMIT (see "Default Limit" section)
$query->offset(50)                // OFFSET

// Execution
foreach ($query as $row) { }      // Iterate
$row = $query->one();             // First row or null
$total = $query->count();         // COUNT(*) ignoring LIMIT
$ids = $query->column();          // First column as array
```

## Model Pattern

Define query methods on your model class. This is explicit, type-safe, and doesn't rely on magic methods.

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
     * @return PartialQuery<User>
     */
    public static function query(): PartialQuery
    {
        return db()->partialQuery('users')->withEntityClass(self::class, false);
    }

    /** @return PartialQuery<User> */
    public static function active(): PartialQuery
    {
        return self::query()->eq('active', 1)->eq('deleted_at', null);
    }

    /** @return PartialQuery<User> */
    public static function admins(): PartialQuery
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

### Custom Row Hydration with SqlRowHydrator

For complex hydration (computed properties, column renaming, nested objects), implement `SqlRowHydrator`:

```php
use mini\Database\SqlRowHydrator;

class User implements SqlRowHydrator
{
    public int $id;
    public string $fullName;
    public Address $address;

    public static function fromSqlRow(array $row): static
    {
        $user = new static();
        $user->id = $row['id'];
        $user->fullName = $row['first_name'] . ' ' . $row['last_name'];
        $user->address = new Address($row['street'], $row['city'], $row['zip']);
        return $user;
    }
}

// Hydration uses fromSqlRow() automatically
$users = User::query()->limit(10);
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

    public static function query(): PartialQuery
    {
        return db()->partialQuery('users')->withEntityClass(self::class, false);
    }

    /**
     * Posts by this user
     * @return PartialQuery<Post>
     */
    public function posts(): PartialQuery
    {
        return Post::query()->eq('user_id', $this->id);
    }

    /**
     * Published posts only
     * @return PartialQuery<Post>
     */
    public function publishedPosts(): PartialQuery
    {
        return $this->posts()->where('published_at IS NOT NULL');
    }

    /**
     * Friends (many-to-many via friendships table)
     * @return PartialQuery<User>
     */
    public function friends(): PartialQuery
    {
        return db()->partialQuery('users', '
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

    public static function query(): PartialQuery
    {
        return db()->partialQuery('posts')->withEntityClass(self::class, false);
    }

    /** Get the author */
    public function author(): ?User
    {
        return User::find($this->user_id);
    }

    /** @return PartialQuery<Comment> */
    public function comments(): PartialQuery
    {
        return Comment::query()->eq('post_id', $this->id);
    }

    /** @return PartialQuery<Post> */
    public static function published(): PartialQuery
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
    public static function query(): PartialQuery
    {
        return db()->partialQuery('users')->withEntityClass(self::class, false);
    }

    /** Users the current user can read */
    public static function readable(): PartialQuery
    {
        return self::query()->eq('organization_id', Auth::orgId());
    }

    /** Users the current user can update */
    public static function updatable(): PartialQuery
    {
        return self::readable()->where('role != ?', ['admin']); // Can't edit admins
    }

    /** Users the current user can delete */
    public static function deletable(): PartialQuery
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
    public static function query(): PartialQuery
    {
        return db()->partialQuery('posts')->withEntityClass(self::class, false);
    }

    /** Posts accessible to current user */
    public static function mine(): PartialQuery
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

## Default Limit: 1000 Rows

Mini applies a default limit of 1000 rows to bulk fetch methods. This is a deliberate safety measure:

- **Prevents accidental full-table scans** - Forgetting a LIMIT won't fetch millions of rows
- **Encourages pagination as a first-class concern** - Developers should design for pagination from the start, not as an afterthought
- **Follows BigTable/NoSQL best practices** - Use indexed cursors for efficient paging

```php
// These apply default 1000-row limit:
foreach ($query as $row) { }     // Stops at 1000
$query->toArray();               // Max 1000 elements
$query->column();                // Max 1000 values

// These do NOT apply default limit:
$query->field();                 // Single scalar value
$query->one();                   // Single row
$query->count();                 // Aggregate, no row data
$query->in('col', $subquery)     // Subqueries bypass limit automatically

// Override when needed:
$query->limit(5000);             // Explicit limit
$query->limit(PHP_INT_MAX);      // Fetch all rows (bypass default)
```

**For pagination**, use index-based cursors rather than OFFSET:

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

The limit applies only to the root query execution - subqueries used with `in()` are not limited since they're part of a larger query.

## Configuration

By default, Mini uses SQLite at `_database.sqlite3`. For other databases:

```php
// _config/PDO.php
return new PDO(
    'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    'username',
    'password'
);
```

Mini auto-configures charset, timezone, error mode, and fetch mode.

## Direct PDO Access

```php
$pdo = db()->getPdo();
$stmt = $pdo->prepare("...");
```
