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

### Insert, Update, Delete

```php
// Insert
db()->exec(
    "INSERT INTO users (name, email) VALUES (?, ?)",
    ['John Doe', 'john@example.com']
);

$userId = db()->getPdo()->lastInsertId();

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

### Batch Inserts

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

## Database Scope

Database connections are **scoped per request**:
- In traditional PHP: One connection per page load
- In long-running apps (Swoole/RoadRunner): Fresh connection per request

This ensures:
- No connection sharing between requests
- Automatic cleanup via garbage collection
- Safe for concurrent requests
