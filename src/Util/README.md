# Util - Utility Classes

This namespace contains utility classes for common programming tasks used throughout Mini and available for application code.

## Overview

- **IdentityMap** - Weak reference-based object identity tracking (prevents duplicates)
- **InstanceStore** - Type-safe singleton storage with array-like access
- **MachineSalt** - Zero-config machine-specific cryptographic salt generation
- **Path** - Cross-platform path manipulation (Windows/Unix compatible)
- **PathsRegistry** - Priority-based file resolution across multiple paths
- **QueryParser** - Parse and match query string criteria with operators

## IdentityMap

Maintains a bidirectional mapping between IDs and objects using weak references, ensuring only one instance exists per ID while allowing garbage collection.

**Use case:** ORM systems, service containers, preventing duplicate entity instances.

```php
use mini\Util\IdentityMap;

// Track User entities by ID
$userMap = new IdentityMap();

// Store user
$user = new User(id: 123, name: 'John');
$userMap->remember($user, 123);

// Later retrieval returns exact same instance
$sameUser = $userMap->tryGet(123);
assert($sameUser === $user); // true - object identity preserved

// When all external references are gone, objects can be garbage collected
unset($user, $sameUser);
$userMap->tryGet(123); // null - object was garbage collected
```

**Key methods:**
- `tryGet(string|int $id): ?object` - Get object by ID (null if not found or GC'd)
- `remember(object $obj, string|int $id): void` - Store object with ID
- `forgetById(string|int $id): void` - Remove mapping by ID
- `forgetObject(object $obj): void` - Remove mapping by object

## InstanceStore

Type-safe storage for singleton instances with WeakMap-like API and interface validation.

**Use case:** Storing typed singletons, plugin registries, service containers.

```php
use mini\Util\InstanceStore;

// Create type-safe store for LoggerInterface implementations
$loggers = new InstanceStore(\Psr\Log\LoggerInterface::class);

// Store with type validation
$loggers['app'] = new AppLogger();
$loggers['db'] = new DatabaseLogger();

// Type enforcement
$loggers['invalid'] = new stdClass(); // Throws InvalidArgumentException

// Array-like access
foreach ($loggers as $key => $logger) {
    $logger->info("Initialized: $key");
}

// Check existence
if ($loggers->has('app')) {
    $logger = $loggers->get('app');
}
```

**Key methods:**
- `get(mixed $key): ?object` - Get instance (null if not found)
- `set(mixed $key, mixed $value): void` - Set instance with type validation
- `has(mixed $key): bool` - Check if key exists
- `delete(mixed $key): bool` - Remove instance
- Implements `ArrayAccess`, `Countable`, `IteratorAggregate`

## MachineSalt

Generates a stable, machine-specific salt for cryptographic operations with zero configuration.

**Use case:** Fallback when `MINI_SALT` environment variable is not set, hashing, encryption keys.

```php
use mini\Util\MachineSalt;

// Get machine-specific salt (64-character hex string)
$salt = MachineSalt::get();

// Use for hashing user-specific data
$userId = 123;
$token = hash('sha256', $userId . $salt . time());

// The salt is stable across requests but unique per machine
// Combines: /etc/machine-id, hostname, PHP binary path, framework path, and random seed
```

**Features:**
- Combines system fingerprint with persistent random salt
- Cached in temp directory (`/tmp/mini_framework_salt.txt`)
- Stable across PHP restarts
- Unique per machine
- Returns SHA-256 hash (64 chars)

## Path

Cross-platform path manipulation utility with lexical operations (no filesystem access required).

**Use case:** Building paths, resolving relative references, cross-platform compatibility.

```php
use mini\Util\Path;

// Create paths
$path = new Path('/var/www/html');
$file = $path->join('config/app.php'); // /var/www/html/config/app.php

// Resolve relative paths (lexical, no filesystem access)
$config = Path::create('/var/www', '../config', 'app.php'); // /var/config/app.php

// Get parent directory
$parent = $path->parent(); // /var/www

// Canonical form (resolves . and ..)
$messy = new Path('/var/www/./html/../config');
$clean = $messy->canonical(); // /var/www/config

// Check if path exists on filesystem
$real = Path::resolve('/var/www', 'html'); // Returns Path or null
if ($real) {
    echo "Path exists: $real"; // Resolves symlinks too
}

// Cross-platform compatibility
$winPath = new Path('C:\\Users\\John\\Documents');
echo $winPath->join('file.txt'); // C:/Users/John/Documents/file.txt (internal)
                                 // C:\Users\John\Documents\file.txt (Windows output)
```

**Key methods:**
- `join(PathInterface|string $target): PathInterface` - Append path segments
- `parent(): PathInterface` - Get parent directory
- `canonical(): PathInterface` - Resolve `.` and `..` segments
- `realpath(): ?PathInterface` - Resolve against filesystem
- `isAbsolute(): bool` / `isRelative(): bool` - Check path type
- `static create(...$parts): PathInterface` - Factory with canonicalization
- `static resolve(...$parts): ?PathInterface` - Factory with filesystem resolution

## PathsRegistry

Priority-based file resolution across multiple paths with caching.

**Use case:** Template/view discovery, plugin systems, theme overrides.

```php
use mini\Util\PathsRegistry;

// Create registry with primary path
$views = new PathsRegistry('/app/views');

// Add fallback paths (most recent added has higher priority)
$views->addPath('/vendor/mini/views');         // Framework fallback
$views->addPath('/vendor/some-bundle/views');  // Bundle fallback

// Resolution order:
// 1. /app/views (primary)
// 2. /vendor/some-bundle/views (most recent fallback)
// 3. /vendor/mini/views (earliest fallback)

// Find first match
$template = $views->findFirst('user/profile.php');
// Returns: /app/views/user/profile.php (if exists)
// Otherwise: /vendor/some-bundle/views/user/profile.php (if exists)
// Otherwise: /vendor/mini/views/user/profile.php (if exists)
// Otherwise: null

// Find all matches (for cascading includes)
$allStyles = $views->findAll('styles.css');
// Returns: ['/app/views/styles.css', '/vendor/bundle/views/styles.css']

// Get all paths
$paths = $views->getPaths(); // ['/app/views', '/vendor/some-bundle/views', ...]
```

**Features:**
- Results cached per filename until paths change
- Duplicates silently ignored
- Natural override cascading (app → bundle → framework)
- Works with Composer autoload order

## QueryParser

Parse and match query string criteria with SQL-like operators.

**Use case:** In-memory filtering, API query parsing, CSV filtering.

```php
use mini\Util\QueryParser;

// Parse from $_GET or query string
$qp = new QueryParser($_GET);
$qp = new QueryParser("age:gte=18&age:lte=65&status=active");

// With whitelist (security)
$qp = new QueryParser($_GET, ['id', 'name', 'age', 'status']);

// Filter array data
$users = [
    ['id' => 1, 'name' => 'John', 'age' => 25, 'status' => 'active'],
    ['id' => 2, 'name' => 'Jane', 'age' => 17, 'status' => 'active'],
    ['id' => 3, 'name' => 'Bob', 'age' => 45, 'status' => 'inactive'],
];

$filtered = array_filter($users, fn($user) => $qp->matches($user));
// Returns: [John (age 25)]

// Get query structure (for SQL generation)
$structure = $qp->getQueryStructure();
// ['age' => ['>=' => '18', '<=' => '65'], 'status' => ['=' => 'active']]
```

**Supported operators:**
- `key=value` - Equality (shorthand for `key:eq=value`)
- `key:eq=value` - Explicit equality
- `key:gt=10` - Greater than
- `key:gte=18` - Greater than or equal
- `key:lt=100` - Less than
- `key:lte=50` - Less than or equal
- `key:like=*pattern*` - Contains pattern
- `key:like=pattern*` - Starts with pattern
- `key:like=*pattern` - Ends with pattern

**Range queries:**
```php
// Age between 18 and 65
$qp = new QueryParser("age:gte=18&age:lte=65");

// Score greater than 80
$qp = new QueryParser("score:gt=80");

// Name contains "john" (case-insensitive)
$qp = new QueryParser("name:like=*john*");
```

**Key methods:**
- `matches($data): bool` - Check if array/object matches criteria
- `getQueryStructure(): array` - Get normalized query structure

## Usage Notes

All utility classes are:
- **Immutable** (where applicable) - Operations return new instances
- **Type-safe** - Use PHP type hints and validation
- **Well-documented** - Comprehensive docblocks with examples
- **Framework-agnostic** - Can be used in any PHP project

These utilities form the foundation for many Mini framework features but are equally useful in application code.
