# Breaking Changes Log

Mini framework is in active internal development. We prioritize clean, simple code over backward compatibility. When we find a better approach, we remove the old implementation rather than maintain redundant code.

This log tracks breaking changes for reference when reviewing old code or conversations.

## Database: PartialQuery API - Separated withEntityClass() from withHydrator() (2025-01-24)

**BREAKING CHANGE**

Split `withHydrator()` into two separate methods for better API clarity:
- `withEntityClass(string $class, array|false $constructorArgs = false)` - Framework-managed hydration
- `withHydrator(\Closure $hydrator)` - Custom closure hydration only

### What Changed

**Before:**
```php
// Class string with constructor args
$users = db()->table('users')->withHydrator(User::class, [db()->getPdo()]);

// Class string without constructor
$users = db()->table('users')->withHydrator(User::class, false);

// Closure
$users = db()->table('users')->withHydrator(
    fn($id, $name, $email) => new User($id, $name, $email)
);
```

**After:**
```php
// Use withEntityClass() for class-based hydration
$users = db()->table('users')->withEntityClass(User::class, [db()->getPdo()]);

// Skip constructor with false
$users = db()->table('users')->withEntityClass(User::class, false);

// Use withHydrator() for closures ONLY
$users = db()->table('users')->withHydrator(
    fn($id, $name, $email) => new User($id, $name, $email)
);
```

### Migration

**Search and replace:**
1. Find: `->withHydrator(SomeClass::class, false)` → Replace: `->withEntityClass(SomeClass::class, false)`
2. Find: `->withHydrator(SomeClass::class, [` → Replace: `->withEntityClass(SomeClass::class, [`
3. Find: `->withHydrator(SomeClass::class)` → Replace: `->withEntityClass(SomeClass::class)`
4. Closures still use `->withHydrator(fn(...) => ...)`

**Why this change:**
- Cleaner API - entity class handling vs custom hydration are fundamentally different
- Better type safety - `withHydrator()` now only accepts `\Closure`
- No more reserved values (`true` is no longer reserved)
- Paves the way for future attribute-based hydration on entity classes

## Database: Added insert() and upsert() + ModelTrait (2025-01-21)

**NEW FEATURES**

Added convenient methods for inserting and upserting rows, plus an Eloquent-style ModelTrait for Active Record pattern support.

### What's New
- **DatabaseInterface::insert()**: Simple INSERT operation returning last insert ID
  - `db()->insert('users', ['name' => 'John', 'email' => 'john@example.com'])`
  - Returns the new row's ID (string)
  - Throws exception on failure (unique constraint violation, etc.)
- **DatabaseInterface::upsert()**: INSERT or UPDATE on conflict
  - `db()->upsert('users', ['email' => 'john@example.com', 'name' => 'John'], 'email')`
  - Supports composite unique keys: `db()->upsert('prefs', $data, 'user_id', 'key')`
  - Dialect-specific SQL generation (MySQL, Postgres, SQLite, SQL Server, Oracle)
  - Returns affected rows (1 for insert/update, 0 for no change)
- **ModelTrait**: Eloquent-style Active Record pattern with generic template support
  - **Entity pattern**: `$user->save()`, `$user->delete()` - instance methods
  - **Repository pattern**: `Users::persist($user)`, `Users::remove($user)` - static methods on POPO
  - `User::find($id)` - Find by primary key with typed return (`User|null`)
  - `User::query()` - Returns typed `PartialQuery<User>` for composable scopes
  - `@template T of object` - Full PHPDoc generic support for type safety
  - Automatic hydration via reflection (no constructor calls needed)
  - Requires: `getTableName()`, `getPrimaryKey()`, `getEntityClass()`, `dehydrate(object $entity)` methods

### Migration

No breaking changes - these are pure additions.

**Using insert():**
```php
// Before
db()->exec(
    "INSERT INTO users (name, email) VALUES (?, ?)",
    ['John', 'john@example.com']
);
$id = db()->lastInsertId();

// After
$id = db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
```

**Using upsert():**
```php
// Insert or update based on email uniqueness
db()->upsert('users', [
    'email' => 'john@example.com',
    'name' => 'John Doe'
], 'email');
```

**Using ModelTrait (Entity pattern):**
```php
class User {
    use ModelTrait;

    public ?int $id = null;
    public string $name;
    public string $email;

    protected static function getTableName(): string { return 'users'; }
    protected static function getPrimaryKey(): string { return 'id'; }
    protected static function getEntityClass(): string { return self::class; }
    protected static function dehydrate(object $entity): array {
        return ['id' => $entity->id, 'name' => $entity->name, 'email' => $entity->email];
    }
}

$user = new User();
$user->name = 'John';
$user->save(); // INSERT

$user->name = 'Updated';
$user->save(); // UPDATE

$user->delete();
```

**Using ModelTrait (Repository pattern with POPO):**
```php
class User {
    public ?int $id = null;
    public string $name;
}

/**
 * @use ModelTrait<User>
 */
class Users {
    use ModelTrait;

    protected static function getTableName(): string { return 'users'; }
    protected static function getPrimaryKey(): string { return 'id'; }
    protected static function getEntityClass(): string { return User::class; }
    protected static function dehydrate(object $entity): array {
        return ['id' => $entity->id, 'name' => $entity->name];
    }
}

$user = new User();
$user->name = 'John';
Users::persist($user); // INSERT

$found = Users::find(1);
$found->name = 'Updated';
Users::persist($found); // UPDATE

Users::remove($found);
```

See `examples/upsert.php`, `examples/model-trait.php`, and `examples/model-trait-repository.php` for complete examples.

## Database: Simplified query() + Object Hydration + SQL Dialects (2025-01-21)

**BREAKING CHANGE + NEW FEATURES**

Simplified database interface by making `query()` return `iterable` and removing `queryStream()`. Added object hydration with full PHPDoc generic support. Added SQL dialect system for database-specific SQL generation.

### Breaking Changes
- **query()** now returns `iterable` (yields rows) instead of `array`
  - Use `iterator_to_array($db->query(...))` if you need an actual array
  - More memory efficient - streams by default instead of buffering
- **queryStream()** removed - no longer needed since `query()` streams
  - Replace `$db->queryStream(...)` with `$db->query(...)`

### What's New
- **PartialQuery::withHydrator()**: Convert rows to typed objects
  - Class name: `->withHydrator(User::class, $constructorArgs)`
  - Skip constructor: `->withHydrator(User::class, false)` - uses `newInstanceWithoutConstructor()`
  - Uses `ReflectionClass::newInstanceArgs()` for efficiency
  - Uses reflection to set private/protected/public properties
  - Reflection properties cached per iteration (thread-safe, no static state)
  - Catches `ReflectionException` and throws `RuntimeException` with context
  - Closure: `->withHydrator(fn(...$row) => new User(...$row))`
  - Reserved: `->withHydrator(User::class, true)` throws `InvalidArgumentException` (future use)
- **Generic template support**: `@template T` for type-safe IDE support
  - `PartialQuery<User>` - IDE knows iteration yields User objects
  - `one()` returns `T|null` - type-safe single row fetch
  - `getIterator()` returns `\Generator<int, T, mixed, void>` - proper generator typing
- **Composable with scopes**: `User::all()` can return `PartialQuery<User>`
- **Works with mutations**: Hydration doesn't prevent `delete()` or `update()`
- **Cleared by select()**: Selecting specific columns clears hydrator and returns `PartialQuery<array>`

### Migration
```php
// Before
$users = $db->query("SELECT * FROM users");
foreach ($users as $user) { ... }

// After - same usage! Iteration works identically
$users = $db->query("SELECT * FROM users");
foreach ($users as $user) { ... }

// If you actually need an array
$users = iterator_to_array($db->query("SELECT * FROM users"));

// queryStream() removed
$stream = $db->queryStream("SELECT * FROM users");  // Remove this
$stream = $db->query("SELECT * FROM users");        // Use this
```

See `examples/partial-query-hydrator.php` for hydration examples.

## Database: Added PartialQuery + Major Improvements (2025-01-20)

**NEW FEATURES + BREAKING CHANGES**

Added immutable query builder for **expert-level composition architecture**, plus composable DELETE/UPDATE operations. Also includes several critical improvements based on expert review.

### What's New
- **PartialQuery class**: Immutable query builder (marked `final`)
- **PartialQueryableTrait**: Adds `table()` method to DatabaseInterface implementations
- **New DatabaseInterface methods**:
  - `quote(mixed $value): string` - Quote values for SQL (auto-detects type)
  - `table(string $table): PartialQuery` - Create query builder
  - `delete(PartialQuery $query): int` - Delete rows matching query (requires WHERE)
  - `update(PartialQuery $query, string|array $set): int` - Update rows matching query

### Breaking Changes
- **exec()** now returns `int` (affected rows) instead of `bool`
- **transaction()** closure now receives `DatabaseInterface` as parameter
- **delete()** requires WHERE clause - throws exception if missing
- **PartialQuery** iterator now streams instead of buffering (removed `fetchAll()`)
- **PartialQuery** marked as `final` - cannot be extended
- **count()** now respects SELECT columns (uses subquery for DISTINCT etc)
- **LIMIT/OFFSET** syntax changed to MySQL-compatible `LIMIT offset, count`

### Primary Value: Architectural Composition
- **Reusable fragments**: Define base queries once, reuse without side effects
- **Safe branching**: Branch query logic without mutation or defensive copying
- **Encapsulated security**: Parameter binding at architectural level
- **Expert tool**: Not a "beginner ORM" but a composition primitive

### Secondary Value: Beginner Safety
- **Safe-by-default**: SQL injection protection built-in
- **IDE autocomplete**: Discoverable API via IDE suggestions

### Key Features
- **Immutable**: Each method returns NEW instance (no side effects)
- **Composable**: Build reusable, non-mutating query fragments
- **Safe defaults**: 1000 row limit prevents accidental full table scans
- **SQL-transparent**: Raw SQL always available via `where()`
- **Iterable**: Use directly in `foreach`
- **Not an ORM**: For complex queries, use `db()->query()` directly

### Usage

**SELECT queries:**
```php
// Basic usage
$users = db()->table('users')
    ->eq('active', 1)
    ->order('created_at DESC')
    ->limit(50);

foreach ($users as $user) {
    echo $user['name'];
}

// Composable scopes
class User {
    public static function spam(): PartialQuery {
        return db()->table('users')->eq('status', 'spam');
    }
}

$recentSpam = User::spam()
    ->where('created_at > ?', [date('Y-m-d', strtotime('-7 days'))]);
```

**DELETE/UPDATE with composable queries:**
```php
// Delete using scopes
$deleted = db()->delete(User::spam());

// Update with array
db()->update(
    db()->table('users')->eq('status', 'inactive'),
    ['status' => 'archived', 'archived_at' => date('Y-m-d H:i:s')]
);

// Update with SQL expression
db()->update(
    db()->table('users')->eq('status', 'active'),
    'login_count = login_count + 1'
);
```

See `src/Database/README.md` for complete documentation.

## PSR-7 Improvements: HTTP Protocol Alignment + Simplifications (2025-01-12)

Multiple PSR-7 improvements: Request/ServerRequest now use request targets (HTTP protocol alignment), PSR-17 factories removed (unnecessary abstraction), and Stream simplified (no serialization).

### What Changed
- **Request constructor**: `new Request($method, $uri, ...)` → `new Request($method, $requestTarget, ...)`
- **ServerRequest constructor**: `new ServerRequest($method, $uri, ..., $queryParams, ...)` → `new ServerRequest($method, $requestTarget, ..., $queryParams=null, ...)`
- **URI construction**: `getUri()` now constructs URI dynamically from request target + headers (unless overridden via `withUri()`)
- **Query params**: `getQueryParams()` now derives from request target by default (unless overridden via `withQueryParams()`)
- **New method**: `getQuery()` returns query string portion of request target
- **HTTPS detection**: ServerRequest detects scheme from `serverParams['HTTPS']` when constructing URI
- **Removed PSR-17**: Deleted `Psr17Factory` and `ServerRequestCreator` - unnecessary abstractions
- **HttpDispatcher**: Now creates ServerRequest directly (SAPI-specific logic belongs in dispatcher)
- **New factory**: `Request::create($method, $uri)` - convenience factory for creating outgoing requests from URIs
- **Stream::cast() simplified**: Removed `$contentType` parameter and all serialization logic - Stream is purely about wrapping stream resources
- **Removed helpers**: Deleted `create_response()`, `create_json_response()`, `emit_response()` - just use `new Response()` directly

### Core Principle
HTTP requests have **method**, **request-target**, **protocol-version**, and **headers** - not URIs. URIs are constructed on-demand from these components.

### Behavior Changes

**Request target is source of truth**:
```php
// Request target stored directly
$request = new ServerRequest('GET', '/path?foo=bar', '', [], null, []);
$request->getRequestTarget();  // '/path?foo=bar'
$request->getQuery();           // 'foo=bar'
$request->getQueryParams();     // ['foo' => 'bar'] (derived)
$request->getUri()->getQuery(); // 'foo=bar' (constructed)
```

**withQueryParams() does NOT change URI** (per PSR-7 spec):
```php
$r2 = $request->withQueryParams(['baz' => 'qux']);
$r2->getRequestTarget();        // '/path?foo=bar' (unchanged!)
$r2->getQueryParams();          // ['baz' => 'qux'] (override)
$r2->getUri()->getQuery();      // 'foo=bar' (unchanged!)
```

**withUri() and withRequestTarget() are independent**:
```php
$r3 = $request->withUri(new Uri('http://example.com/other?x=y'));
$r3->getRequestTarget();        // '/path?foo=bar' (unchanged!)
$r3->getUri()->getQuery();      // 'x=y' (URI override)
$r3->getQueryParams();          // ['foo' => 'bar'] (from request target!)
```

**Relative URI when no Host header**:
```php
$request = new Request('GET', '/path?query', '', []);
$request->getUri();  // Returns relative URI: '/path?query'
```

**HTTPS detection from server params**:
```php
$request = new ServerRequest(
    'GET', '/secure', '',
    ['Host' => 'example.com'],
    null,
    ['HTTPS' => 'on']
);
$request->getUri();  // 'https://example.com/secure'
```

### Migration

**Most applications**: No changes needed - HttpDispatcher handles request creation internally.

**Creating outgoing HTTP requests** (HTTP clients, testing):
```php
// Before
$request = new Request('GET', 'http://example.com/path?foo=bar', '');

// After - Use convenience factory
$request = Request::create('GET', 'http://example.com/path?foo=bar');

// Or direct constructor with request target
$request = new Request('GET', '/path?foo=bar', '', ['Host' => 'example.com']);
```

**Creating responses** (simple and direct):
```php
// Before
\mini\Http\create_response(200, 'Hello');
\mini\Http\create_json_response(['data' => 'value']);

// After
new Response('Hello', [], 200);
new Response(json_encode(['data' => 'value']), ['Content-Type' => 'application/json'], 200);
```

### Why These Changes?

1. **HTTP protocol correctness**: Requests ARE request targets, not URIs
2. **PSR-7 compliance**: `withQueryParams()` must not affect URI (was incorrectly coupled before)
3. **Cleaner separation**: URI, query params, and request target have distinct lifecycles
4. **Performance**: No need to construct/store URI object during request creation
5. **No PSR-17 needed**: Mini doesn't need factory abstractions - dispatchers create requests directly
6. **Environment-specific**: HttpDispatcher owns SAPI logic; future FiberHttpDispatcher will own its own creation logic
7. **Stream responsibility**: Stream wraps stream resources - serialization belongs in converters/helpers

## Native PSR-7 Implementation (Replaced Nyholm)

Mini now includes its own PSR-7 HTTP message implementation, removing the dependency on `nyholm/psr7` and `nyholm/psr7-server`.

### What Changed
- **Removed dependencies**: `nyholm/psr7` and `nyholm/psr7-server` no longer required
- **New classes**: All PSR-7 classes now in `mini\Http\Message\` namespace
- **API compatible**: Drop-in replacement, no code changes needed for standard PSR-7 usage
- **Response constructor signature**: Mini's `Response` uses `($body, $headers, $statusCode, $reasonPhrase, $protocolVersion)` instead of Nyholm's `($statusCode, $headers, $body)`

### New Classes
All classes implement their respective PSR-7 interfaces:
- `mini\Http\Message\Request`
- `mini\Http\Message\Response`
- `mini\Http\Message\ServerRequest`
- `mini\Http\Message\Stream`
- `mini\Http\Message\Uri`
- `mini\Http\Message\UploadedFile`
- `mini\Http\Message\Psr17Factory` (PSR-17 factory)
- `mini\Http\Message\ServerRequestCreator` (creates ServerRequest from globals)

### Migration

**Most applications**: No changes needed - Mini's default converters and HttpDispatcher already updated.

**If you used Nyholm classes directly**:
```php
// Before
use Nyholm\Psr7\Response;
$response = new Response(200, ['Content-Type' => 'text/html'], $body);

// After
use mini\Http\Message\Response;
$response = new Response($body, ['Content-Type' => 'text/html'], 200);
```

**Factory usage** (rare - most apps use helper functions):
```php
// Before
use Nyholm\Psr7\Factory\Psr17Factory;

// After
use mini\Http\Message\Psr17Factory;
```

### Why This Change?

1. **Zero dependencies**: Aligns with Mini's zero-dependency architecture
2. **Extendable**: Nyholm's implementation prohibited extending classes
3. **Control**: Full control over PSR-7 behavior and fixes
4. **Correctness**: Nyholm had implementation issues we needed to work around

## PSR-7 url() Function with CDN Support

The `url()` function now returns `UriInterface` instead of string and includes proper relative path resolution and CDN support.

### Changed Signature
```php
// Before
function url($path = '', array $query = []): string

// After
function url(string|UriInterface $path = '', array $query = [], bool $cdn = false): UriInterface
```

### New Behavior
- Returns `UriInterface` (PSR-7) instead of string
- Properly resolves relative paths (`.`, `..`)
- Strips scheme/host from input URLs - always resolves against base URL
- Supports CDN via `$cdn` parameter
- UriInterface is stringable - templates still work: `<?= url('/path') ?>`

### New Environment Variable
- `MINI_CDN_URL` - CDN base URL for static assets (optional, defaults to `baseUrl`)

### Migration

**Templates** - No changes needed (UriInterface is stringable):
```php
<a href="<?= url('/users') ?>">Users</a>
```

**Type hints** - Update if you type-hinted the return value:
```php
// Before
$url = url('/path');  // string

// After
$url = url('/path');  // UriInterface (but still works as string)
```

**CDN usage**:
```php
// Static assets via CDN
<link href="<?= url('/css/app.css', cdn: true) ?>" rel="stylesheet">
<img src="<?= url('/images/logo.png', cdn: true) ?>" alt="Logo">
```

## Phase System Introduction

The phase system replaces individual lifecycle hooks with a comprehensive state machine.

### Removed Methods
- `Mini::enterBootstrapPhase()` - use `Mini::$mini->phase->trigger(Phase::Bootstrap)`
- `Mini::enterReadyPhase()` - use `Mini::$mini->phase->trigger(Phase::Ready)`
- `Mini::enterFailedPhase()` - use `Mini::$mini->phase->trigger(Phase::Failed)`
- `Mini::enterShutdownPhase()` - use `Mini::$mini->phase->trigger(Phase::Shutdown)`
- `Mini::getCurrentPhase()` - use `Mini::$mini->phase->getCurrentState()`
- `Mini::enterRequestContext()` - framework now uses phase transitions
- `Mini::exitRequestContext()` - framework now uses phase transitions

### Removed Hooks
- `Mini::$onRequestReceived` - use `Mini::$mini->phase->onEnteringState(Phase::Ready, fn() => ...)`
- `Mini::$onAfterBootstrap` - use `Mini::$mini->phase->onEnteredState(Phase::Ready, fn() => ...)`

### Migration Examples

**Before:**
```php
Mini::$mini->onRequestReceived->listen(function() {
    // Authentication logic
});

Mini::$mini->onAfterBootstrap->listen(function() {
    // Output buffering setup
});
```

**After:**
```php
// Fires when entering Ready phase (before phase change completes)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Authentication logic
});

// Fires after Ready phase entered (after phase change completes)
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Output buffering setup
});
```
