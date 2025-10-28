# Mini Tables

Backend-agnostic data access layer for the Mini framework. Provides a consistent API for working with CSV files, SQLite, MySQL, PostgreSQL, and custom data sources.

## Features

- **Backend Agnostic** - Same API works with CSV, SQLite, MySQL, PostgreSQL, or custom sources
- **Type Safe** - Full PHPDoc generics support for IDE autocomplete
- **Query Builder** - Fluent, immutable query interface
- **Automatic Codecs** - Handles type conversion between PHP and storage backends
- **Attribute-Based** - Configure columns with PHP 8 attributes
- **Identity Map** - Ensures object identity within request scope
- **Change Tracking** - Clone-based dirty detection for efficient saves

## Installation

Currently bundled with `fubber/mini`. In the future:

```bash
composer require fubber/mini-tables
```

## Basic Usage

### Register a Repository

```php
// config/bootstrap.php
use mini\Tables\CsvRepository;
use mini\Mini;

// Register a CSV-backed repository using factory Closure
repositories()->set(User::class, fn() => new CsvRepository(
    User::class,
    Mini::$mini->root . '/data/users.csv'
));
```

**Important:** Repositories must be registered as Closures that return repository instances. This ensures fresh database connections per request in long-running applications (Swoole, RoadRunner, FrankenPHP).

### Use the Table API

```php
use function mini\table;

// Create
$user = table(User::class)->create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Load by ID
$user = table(User::class)->load(123);

// Query
$admins = table(User::class)
    ->where('role', 'admin')
    ->where('active', true)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->findAll();

// Update
$user->email = 'newemail@example.com';
table(User::class)->save($user);

// Delete
table(User::class)->delete($user);
```

## Supported Backends

Mini Tables supports multiple storage backends with a consistent API:

### CSV Files

Perfect for simple data storage, configuration files, or prototyping:

```php
use mini\Tables\CsvRepository;
use mini\Mini;

repositories()->set(Product::class, fn() => new CsvRepository(
    Product::class,
    Mini::$mini->root . '/data/products.csv'
));
```

**Features:**
- Automatic CSV header detection
- String-based storage with automatic type conversion
- File locking for concurrent access
- Human-readable and version-control friendly

### SQLite Database

Lightweight database with full SQL support:

```php
use mini\Tables\DatabaseRepository;
use mini\Tables\CodecStrategies\SQLiteCodecStrategy;
use function mini\db;

repositories()->set(Order::class, fn() => new DatabaseRepository(
    db: db(),  // Fresh connection per request
    modelClass: Order::class,
    codecStrategy: new SQLiteCodecStrategy(),
    tableName: 'orders'
));
```

### MySQL Database

Most popular database with excellent performance:

```php
use mini\Tables\DatabaseRepository;
use mini\Tables\CodecStrategies\MySQLCodecStrategy;
use function mini\db;

repositories()->set(Customer::class, fn() => new DatabaseRepository(
    db: db(),  // Fresh connection per request
    modelClass: Customer::class,
    codecStrategy: new MySQLCodecStrategy(),
    tableName: 'customers'
));
```

### PostgreSQL Database

Production-ready database with advanced features:

```php
use mini\Tables\DatabaseRepository;
use mini\Tables\CodecStrategies\PostgreSQLCodecStrategy;
use function mini\db;

repositories()->set(Customer::class, fn() => new DatabaseRepository(
    db: db(),  // Fresh connection per request
    modelClass: Customer::class,
    codecStrategy: new PostgreSQLCodecStrategy(),
    tableName: 'customers'
));
```

### Custom Repositories

Implement `RepositoryInterface` for custom backends:

```php
use mini\Tables\RepositoryInterface;

class RedisRepository implements RepositoryInterface {
    // Implement: load, save, delete, findAll, etc.
}

repositories()->set(Session::class, fn() => new RedisRepository());
```

## Query Builder

The `Table` class provides an immutable, fluent query interface:

```php
$users = table(User::class)
    ->where('age:gte', 18)           // age >= 18
    ->where('status', 'active')      // status = 'active'
    ->where('city:in', ['Oslo', 'Bergen'])  // city IN (...)
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->offset(40)
    ->findAll();

// Count
$count = table(User::class)->where('role', 'admin')->count();

// Iteration
foreach (table(Product::class)->where('price:lt', 100) as $product) {
    echo $product->name;
}

// First match
$user = table(User::class)->where('email', 'john@example.com')->first();
```

### Query Operators

- `=` - Equals (default)
- `:gt` - Greater than
- `:gte` - Greater than or equal
- `:lt` - Less than
- `:lte` - Less than or equal
- `:neq` - Not equal
- `:in` - In array
- `:contains` - String contains (substring match)

## Model Classes

Define simple PHP classes with public properties:

```php
class User {
    public ?int $id = null;
    public string $name;
    public string $email;
    public string $role = 'user';
    public bool $active = true;
    public ?\DateTimeImmutable $created_at = null;
}
```

### Using Attributes

Control storage behavior with attributes:

```php
use mini\Attributes\Column;
use mini\Attributes\DateTimeColumn;

class Article {
    public ?int $id = null;

    #[Column(name: 'article_title')]
    public string $title;

    #[DateTimeColumn(format: 'Y-m-d H:i:s')]
    public ?\DateTimeImmutable $published_at = null;

    #[Column(ignore: true)]
    public array $cachedData = [];  // Not stored
}
```

## Type Conversion

Tables automatically handles type conversion between PHP and storage:

```php
class Product {
    public ?int $id = null;
    public string $name;
    public float $price;              // → "19.99" in CSV
    public bool $available;           // → "1" or "0" in CSV
    public array $tags;               // → JSON in storage
    public ?\DateTimeImmutable $created_at = null;  // → ISO-8601 string
}
```

## Codecs

Codecs handle conversion between PHP types and backend storage formats:

### Built-in Codecs

- **StringCodec** - String values
- **IntegerCodec** - Integer values
- **FloatCodec** - Float values
- **BooleanCodec** - Boolean values (1/0, true/false)
- **ArrayCodec** - Arrays (JSON encoding)
- **JsonCodec** - JSON data
- **DateTimeCodec** - DateTime/DateTimeImmutable objects

### Custom Codecs

Create custom codecs for specialized types:

```php
use mini\Tables\Codecs\CodecInterface;
use mini\Tables\Codecs\StringCodecInterface;

class EmailCodec implements CodecInterface, StringCodecInterface {
    public function encode(mixed $value): string {
        return strtolower(trim($value));
    }

    public function decode(string $value): string {
        return strtolower(trim($value));
    }
}
```

## Change Tracking

Tables uses a clone-based approach for efficient change detection:

```php
$user = table(User::class)->load(123);

// Original state is automatically cloned
$user->name = 'New Name';

// Only changed properties are updated
table(User::class)->save($user);  // Only updates 'name' column
```

## Identity Map

Objects loaded with the same ID return the same instance:

```php
$user1 = table(User::class)->load(123);
$user2 = table(User::class)->load(123);

assert($user1 === $user2);  // Same object instance
```

## Transaction Support

When using DatabaseRepository:

```php
$pdo = new PDO('sqlite:mydb.db');
$pdo->beginTransaction();

try {
    $user = table(User::class)->create(['name' => 'John']);
    $order = table(Order::class)->create(['user_id' => $user->id]);

    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

## Advanced: Scalar vs Database Codecs

Different backends require different encoding strategies:

### Scalar Backends (CSV, JSON files)
- Everything stored as strings
- ScalarCodecStrategy converts all types to/from strings
- Example: `true` → `"1"`, `DateTime` → `"2024-01-15T10:30:00Z"`

### Database Backends (SQLite, MySQL, PostgreSQL)
- Native type support
- SQLiteCodecStrategy / MySQLCodecStrategy / PostgreSQLCodecStrategy use native types
- Example: `true` → `INTEGER 1` (MySQL: TINYINT(1)), `DateTime` → `TEXT ISO-8601`

The framework automatically selects the appropriate strategy based on your repository type.

## Architecture

```
mini\Tables\
├── Repository.php              # User-facing API wrapper
├── Table.php                   # Immutable query builder
├── RepositoryInterface.php     # Backend contract
├── CsvRepository.php           # CSV backend
├── DatabaseRepository.php      # SQL database backend
├── ScalarRepository.php        # Base for string-based backends
├── Codecs/                     # Type conversion interfaces
└── CodecStrategies/            # Backend-specific strategies
```

## Best Practices

### 1. Use Type Hints
```php
/** @var User $user */
$user = table(User::class)->load(123);
```

### 2. Create Repositories in Bootstrap
```php
// config/bootstrap.php - centralize configuration
use mini\Tables\CodecStrategies\MySQLCodecStrategy;

repositories()->set(User::class, fn() => new CsvRepository(User::class, $csvPath));
repositories()->set(Order::class, fn() => new DatabaseRepository(
    db: db(),
    modelClass: Order::class,
    codecStrategy: new MySQLCodecStrategy(),
    tableName: 'orders'
));
```

### 3. Use Readonly Properties for IDs
```php
class User {
    public readonly ?int $id;  // Prevents accidental modification
}
```

### 4. Leverage Attributes for Complex Mapping
```php
#[Column(name: 'user_full_name')]
public string $fullName;

#[DateTimeColumn(format: 'Y-m-d')]
public ?\DateTimeImmutable $birthDate = null;
```

### 5. Separate Concerns
```php
// Model - just properties
class User { }

// Repository - registered in bootstrap
repositories()->set(User::class, ...);

// Usage - in controllers/services
$user = table(User::class)->load($id);
```

## Testing

Tables work great with in-memory backends for testing:

```php
// tests/UserTest.php
$csvPath = tempnam(sys_get_temp_dir(), 'test_users_');
repositories()->set(User::class, fn() => new CsvRepository(User::class, $csvPath));

$user = table(User::class)->create(['name' => 'Test User']);
assert($user->id !== null);
```

## Future Separate Package

This feature is designed to be extracted as `fubber/mini-tables`:
- Self-contained in `mini\Tables` namespace
- Depends on core `fubber/mini` framework
- Fully optional - core Mini works without Tables

## License

MIT License - see [LICENSE](../../LICENSE)
