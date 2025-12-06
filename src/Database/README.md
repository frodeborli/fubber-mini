# Database

Mini's database layer is a thin wrapper over PDO with an immutable query builder.

## Quick Start

```php
// Raw queries
$users = db()->query("SELECT * FROM users WHERE active = ?", [1]);
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = db()->queryField("SELECT COUNT(*) FROM users");
$ids = db()->queryColumn("SELECT id FROM users WHERE role = ?", ['admin']);

// Mutations
db()->exec("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'john@example.com']);
db()->exec("UPDATE users SET active = ? WHERE id = ?", [0, 123]);
db()->exec("DELETE FROM users WHERE id = ?", [123]);

// Last insert ID
$userId = db()->lastInsertId();

// Transactions (auto rollback on exception)
db()->transaction(function() {
    db()->exec("INSERT INTO users ...");
    db()->exec("INSERT INTO activity_log ...");
});
```

## PartialQuery: Composable Queries

PartialQuery is an immutable query builder. Each method returns a NEW instance, making queries safe to reuse and compose.

```php
// Basic usage - iterate directly
foreach (db()->query('users')->eq('active', 1) as $user) {
    echo $user['name'];
}

// Composition - original unchanged
$active = db()->query('users')->eq('active', 1);
$admins = $active->eq('role', 'admin');      // New instance
$mods = $active->eq('role', 'moderator');    // New instance

// Methods
$query->eq('column', $value)      // = (NULL becomes IS NULL)
$query->lt('column', $value)      // <
$query->lte('column', $value)     // <=
$query->gt('column', $value)      // >
$query->gte('column', $value)     // >=
$query->in('column', [...])       // IN (...)
$query->in('column', $subquery)   // IN (SELECT ...) via CTE
$query->where('sql', $params)     // Raw WHERE clause
$query->order('created_at DESC')  // ORDER BY
$query->limit(100)                // LIMIT (default: 1000)
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
        return db()->query('users')->withEntityClass(self::class, false);
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

## Relationships

Define relationship methods explicitly. No magic, no autowiring - just clear, predictable code you write once.

```php
class User
{
    public int $id;
    public string $name;

    public static function query(): PartialQuery
    {
        return db()->query('users')->withEntityClass(self::class, false);
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
        return db()->query('
            SELECT u.* FROM users u
            INNER JOIN friendships f ON (
                (f.friend_id = u.id AND f.user_id = ?)
                OR (f.user_id = u.id AND f.friend_id = ?)
            )
        ', [$this->id, $this->id], 'users')
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
        return db()->query('posts')->withEntityClass(self::class, false);
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

## Authorization Pattern: `::mine()`

Use `::mine()` as a security boundary. It's shorter than `::query()`, making the secure path the default.

```php
class Post
{
    public static function query(): PartialQuery
    {
        return db()->query('posts')->withEntityClass(self::class, false);
    }

    /**
     * Posts accessible to current user
     * @return PartialQuery<Post>
     */
    public static function mine(): PartialQuery
    {
        $userId = auth()->getUserId();

        return self::query()->where('
            user_id = ? OR visibility = "public"
        ', [$userId]);
    }

    public static function find(int $id): ?Post
    {
        return self::mine()->eq('id', $id)->one();  // Authorized!
    }
}

// Always use ::mine() for user-facing queries
$posts = Post::mine()->order('created_at DESC')->limit(20);
$post = Post::find(123);  // Returns null if not authorized
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

The default 1000-row limit protects against accidental mass operations.

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
