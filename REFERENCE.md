# Mini Framework - API Reference

Quick reference for Mini framework functions and classes.

## Core Functions

### Framework Bootstrap

```php
bootstrap(): void       # Initialize framework (error handlers, phases)
dispatch(): void        # Handle the current HTTP request (entry point in html/index.php)
```

### Translation

```php
t(string $text, array $vars = []): Translatable    # Create translatable text
tjs(string $text, array $vars = []): \Stringable   # Translatable escaped for inline JS
# Translator service: Mini::$mini->get(mini\I18n\TranslatorInterface::class)
```

### HTML & Output

```php
h(string $str): string                            # HTML escape
render(string $template, array $vars = []): string  # Render template from _views/
```

### Template Inheritance

Inside templates, `$this` provides helpers for layout inheritance:

```php
$this->extend(string $layout)                          # Extend parent layout
$this->block(string $name, ?string $value = null)      # Define block (dual-use)
$this->end()                                           # End buffered block
$this->show(string $name, string $default = '')        # Output block with default
```

**Dual-Use `$this->block()` Syntax:**
```php
// Inline: set block to value directly
$this->block('title', 'My Page');

// Buffered: capture block content
$this->block('content'); ?>
  <p>Content here</p>
<?php $this->end();
```

**Example:**
```php
// Child template (child.php)
<?php $this->extend('layout.php'); ?>
<?php $this->block('title', 'My Page'); ?>
<?php $this->block('content'); ?><p>Page content</p><?php $this->end(); ?>

// Parent layout (layout.php)
<html><head><title><?php $this->show('title', 'Untitled'); ?></title></head>
<body><?php $this->show('content'); ?></body></html>

// Including sub-templates (partials)
<?= mini\render('_user-card.php', ['user' => $currentUser]) ?>

// Multi-level inheritance (3+ levels)
// base.php → layout.php → page.php
```

### URL Generation

```php
url(string|UriInterface $path = '', array $query = [], bool $cdn = false): UriInterface
redirect(string $url, int $statusCode = 302): void # Redirect
current_url(): string                              # Get current URL
```

### Session & Flash Messages

`$_SESSION` is a fiber-safe request-scoped proxy (see `src/Session/`); no manual
`session_start()` is needed.

```php
flash_set(string $type, string $message): void  # Set flash message
flash_get(): array                              # Get and clear flash messages
```

### Database

```php
db(): DatabaseInterface  # Get request-scoped database instance
```

**DatabaseInterface Methods:**
```php
query(string $sql, array $params = []): Query          # Composable, lazy query facade
queryOne(string $sql, array $params = []): ?object     # First row or null
queryField(string $sql, array $params = []): mixed     # First field of first row
queryColumn(string $sql, array $params = []): array    # First column as array
exec(string $sql, array $params = []): int             # Execute, returns affected rows
lastInsertId(): ?string                                # Get last insert ID
tableExists(string $tableName): bool                   # Check if table exists
transaction(\Closure $task): mixed                     # Run closure in transaction
insert(string $table, array $data): string             # INSERT, returns insert ID
upsert(string $table, array $data, string ...$conflictColumns): int
update(Query|PartialQuery $query, string|array $set, array $params = []): int
delete(Query|PartialQuery $query): int
quote(mixed $value): string                            # Quote a value for SQL
quoteIdentifier(string $identifier): string            # Quote a table/column name
getDialect(): SqlDialect                               # Active SQL dialect
```

### Query (Composable Query Facade)

`db()->query('SELECT ...')` returns an **immutable** `mini\Database\Query`.
Each method returns a new instance; WHERE/ORDER/LIMIT compose onto the base SQL.
Rows are `stdClass` objects unless `withEntityClass()`/`withHydrator()` is used.

```php
eq/gt/gte/lt/lte(string $column, $value): static  # Comparison predicates
like(string $column, string $pattern): static     # WHERE column LIKE pattern
in(string $column, array|Query $values): static   # WHERE column IN (...)
where(string $sql, array $params = []): static    # Raw WHERE fragment with params
columns(string ...$columns): static               # Project columns
order(?string $spec): static                      # ORDER BY 'col DESC, other ASC'
limit(int $n): static / offset(int $n): static
distinct(): static
one(): mixed                                      # First result or null
all(): array                                      # Materialize all rows
column(): array                                   # First column as array
field(): mixed                                    # First field of first row
count(): int / exists(): bool
withEntityClass(string $class, array|false $ctorArgs = false): static
withHydrator(\Closure $hydrator): static
# Also iterable (lazy) and JsonSerializable
```

### Cache

```php
cache(?string $namespace = null): CacheInterface  # Get cache instance
```

**CacheInterface Methods (PSR-16):**
```php
get(string $key, mixed $default = null): mixed
set(string $key, mixed $value, null|int $ttl = null): bool
delete(string $key): bool
clear(): bool
has(string $key): bool
getMultiple(iterable $keys, mixed $default = null): iterable
setMultiple(iterable $values, null|int $ttl = null): bool
deleteMultiple(iterable $keys): bool
```

### Logging

```php
log(): LoggerInterface  # Get PSR-3 logger instance
```

**LoggerInterface Methods (PSR-3):**
```php
emergency(string $message, array $context = []): void
alert(string $message, array $context = []): void
critical(string $message, array $context = []): void
error(string $message, array $context = []): void
warning(string $message, array $context = []): void
notice(string $message, array $context = []): void
info(string $message, array $context = []): void
debug(string $message, array $context = []): void
```

### Internationalization

```php
fmt(): Fmt              # Get formatter instance
```

**Fmt Static Methods:**
```php
Fmt::currency(float $amount, string $currencyCode): string
Fmt::dateShort(\DateTimeInterface|string $date): string
Fmt::dateLong(\DateTimeInterface|string $date): string
Fmt::timeShort(\DateTimeInterface|string $time): string
Fmt::dateTimeShort(\DateTimeInterface|string $dt): string
Fmt::dateTimeLong(\DateTimeInterface|string $dt): string
Fmt::number(float|int $number, int $decimals = 0): string
Fmt::percent(float $ratio, int $decimals = 0): string
Fmt::fileSize(int $bytes): string
```

### Authentication

`auth()` returns the `mini\Auth\Auth` facade. The application provides the
implementation via a `_config/mini/Auth/AuthInterface.php` config file.

```php
auth(): Auth                          # Get auth facade
auth()->isAuthenticated(): bool
auth()->getUserId(): mixed
auth()->getClaim(string $name): mixed
auth()->hasRole(string $role): bool
auth()->hasPermission(string $permission): bool
auth()->requireLogin(): Auth          # Throws AuthenticationRequiredException
auth()->requireRole(string $role): Auth
auth()->requirePermission(string $permission): Auth
```

**AuthInterface (implement this):**
```php
interface AuthInterface {
    public function isAuthenticated(): bool;
    public function getUserId(): mixed;
    public function getClaim(string $name): mixed;
    public function hasRole(string $role): bool;
    public function hasPermission(string $permission): bool;
}
```

### CSRF Protection

```php
csrf(string $action, string $fieldName = '__nonce__'): CSRF  # Create CSRF token
```

**CSRF Class Methods:**
```php
$token = new CSRF('delete-post');           # Create token for action
$token = new CSRF('update-user', 'token');  # Custom field name

$token->getToken(): string                  # Get token string
$token->verify(?string $token, float $maxAge = 86400): bool  # Verify token
$token->__toString(): string                # Output hidden input field
```

**Usage Example:**
```php
// Generate token
$nonce = csrf('delete-post');
echo render('form.php', ['nonce' => $nonce]);

// In template
<form method="post">
  <?= $nonce ?>
  <button>Delete</button>
</form>

// Verify token
$nonce = csrf('delete-post');
if ($nonce->verify($_POST['__nonce__'])) {
    // Process form
}
```

**Security Features:**
- Tokens signed with HMAC-SHA256 using `Mini::$mini->salt`
- Salt auto-generated from machine fingerprint + persistent random (zero-config)
- Includes session ID and user agent for additional security
- Time-based expiration (default 24 hours, customizable)
- IP address validation
- Self-contained tokens (no server-side storage needed)

## Core Classes

### Translatable

```php
class Translatable implements \Stringable {
    public function getSourceText(): string
    public function getVars(): array
    public function getSourceFile(): ?string
    public function __toString(): string  # Returns translated text
}
```

### Translator

```php
class Translator {
    public function setLanguageCode(string $languageCode): void
    public function trySetLanguageCode(string $languageCode): bool
    public function getLanguageCode(): string
}
```

### Mini (Container)

```php
class Mini implements ContainerInterface {
    public static Mini $mini;                  # Global instance
    public readonly string $root;              # Project root
    public readonly Mini\PathRegistries $paths;# Path registries (overlay file system)
    public readonly ?string $docRoot;          # Document root
    public readonly ?string $baseUrl;          # Base URL
    public readonly ?string $cdnUrl;           # CDN URL (for url(..., cdn: true))
    public readonly bool $debug;               # Debug mode
    public readonly string $locale;            # Default locale
    public readonly string $timezone;          # Default timezone
    public readonly string $sqlTimezone;       # Timezone for SQL timestamps
    public readonly string $defaultLanguage;   # Default language
    public readonly string $salt;              # Cryptographic salt (auto-generated or MINI_SALT)
    public readonly Hooks\StateMachine $phase; # Lifecycle phase state machine

    public function addService(string $id, Lifetime $lifetime, Closure $factory): void
    public function has(string $id): bool
    public function get(string $id): mixed
    public function loadConfig(string $filename, mixed $default = null): mixed
    public function loadServiceConfig(string $className, mixed $default = null): mixed
}
```

### Lifetime Enum

```php
enum Lifetime {
    case Singleton;   # One instance per application
    case Scoped;      # One instance per request
    case Transient;   # New instance every time
}
```

## HTTP Exceptions

Exceptions convert to responses via the exception converter registry (see PATTERNS.md).

```php
throw new mini\Exceptions\NotFoundException($message);                # 404
throw new mini\Exceptions\AccessDeniedException($message);            # 403
throw new mini\Exceptions\AuthenticationRequiredException($message);  # 401
throw new mini\Exceptions\BadRequestException($message);              # 400
```

## Routing

### File-Based Routes

Files in `_routes/` directory map to URLs. Wildcards: `_.php` matches a single
segment, `_/` matches a directory segment; captured values land in `$_GET[0]`,
`$_GET[1]`, ... (nearest wildcard at index 0).

```
_routes/index.php              → /
_routes/users.php              → /users
_routes/api/posts.php          → /api/posts
_routes/users/_.php            → /users/{anything}   ($_GET[0])
```

### Strict Route Contract

A route file **must** return one of:

- a `Psr\Http\Message\ResponseInterface` (e.g. `JsonResponse`, `HtmlResponse`)
- a `mini\Http\ResponseAggregate` (any class with `getResponse(): ResponseInterface`)
- a `Closure` — an inline handler with typed parameter injection; its return
  value converts to a response via the converter registry
- a `Psr\Http\Server\RequestHandlerInterface` — typically from `__DEFAULT__.php`

Anything else throws — including `echo`/`header()` during route file inclusion.

```php
// _routes/api/ping.php
<?php return new mini\Http\Message\JsonResponse('pong');

// _routes/api/users/_.php — $_0 receives the wildcard, type-coerced
<?php return fn(int $_0) => User::find($_0);
```

### Directory Routes (Sub-Routers)

`_routes/api/users/__DEFAULT__.php` mounts a PSR-15 handler for the whole
`/api/users/*` subtree — a controller extending `mini\Controller\AbstractController`
(pattern-based routing via `#[GET]`/`#[POST]` attributes) or any
`RequestHandlerInterface` (even a mounted Slim/Mezzio app):

```php
<?php return new UserController();
```

## Configuration

### Environment Variables

```bash
MINI_ROOT=/path/to/project      # Project root
MINI_CONFIG_ROOT=/path/config   # Config directory
MINI_ROUTES_ROOT=/path/routes   # Routes directory
MINI_VIEWS_ROOT=/path/views      # Views directory
MINI_LOCALE=nb_NO                # Default locale
MINI_TIMEZONE=Europe/Oslo        # Default timezone
MINI_LANG=nb                     # Default language
MINI_SALT=your-random-salt-here  # Cryptographic salt (optional, auto-generated if not set)
DEBUG=1                          # Debug mode
```

### Config Files

All config files in `_config/` directory:

- `PDO.php` - PDO factory override
- `Psr/Log/LoggerInterface.php` - Logger override
- `Psr/SimpleCache/CacheInterface.php` - Cache override

## CLI Commands

Subcommands are discovered via `extra.mini.commands` in composer.json — packages
(and the host application) can contribute their own.

```bash
vendor/bin/mini serve            # Start development server
vendor/bin/mini test [path]      # Run tests
vendor/bin/mini migrations       # Run database migrations (tracking + rollback)
vendor/bin/mini translations     # Manage translation files
vendor/bin/mini docs             # Browse PHP documentation
vendor/bin/mini db               # Interactive database shell (-v for VirtualDatabase)
vendor/bin/mini aspects          # Sync aspect bundles with Composer
vendor/bin/mini benchmark        # Benchmark framework performance
```

## ICU MessageFormat Syntax

### Plurals

```php
t("{count, plural, =0{no items} =1{one item} other{# items}}", ['count' => 5])
```

### Ordinals

```php
t("{place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}", ['place' => 21])
```

### Select

```php
t("{gender, select, male{He} female{She} other{They}}", ['gender' => 'male'])
```

### Date/Time/Number Formatting

```php
t("Today is {date, date, full}", ['date' => new DateTime()])
t("Price: {amount, number, currency}", ['amount' => 19.99])
```

## Testing

Run tests with `vendor/bin/mini test [path]` (in this repo: `php bin/mini test tests/...`).
Test files are plain PHP scripts; assertions live in `tests/assert.php`:

```php
require __DIR__ . '/assert.php';

assert_eq($expected, $actual);
assert_true($condition);
assert_throws(fn() => dangerousCode(), SomeException::class);
```

## Native PHP Integrations

Mini uses native PHP directly where appropriate:

### Request Data

```php
$_GET['param']              # Query parameters
$_POST['field']             # Form data
$_FILES['upload']           # File uploads
$_SERVER['REQUEST_METHOD']  # HTTP method
$_SERVER['HTTP_*']          # Request headers
$_COOKIE['name']            # Cookies
```

### Locale & Formatting

```php
\Locale::setDefault('nb_NO')             # Set locale
\Locale::getDefault()                     # Get locale
date_default_timezone_set('Europe/Oslo')  # Set timezone
date_default_timezone_get()               # Get timezone
```

### Intl Classes

```php
$formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::CURRENCY);
$formatter = new \IntlDateFormatter(\Locale::getDefault(), ...);
$formatter = new \MessageFormatter(\Locale::getDefault(), $pattern);
$collator = new \Collator(\Locale::getDefault());
```

## Service Override Pattern

Override framework services using config files:

```php
// _config/Psr/Log/LoggerInterface.php
return new \Monolog\Logger('app', [
    new \Monolog\Handler\StreamHandler('php://stderr'),
]);
```

```php
// _config/PDO.php
return new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
```

```php
// _config/mini/UUID/FactoryInterface.php
return new \mini\UUID\UUID4Factory();  // Use v4 instead of v7
```

See `PATTERNS.md` for detailed examples.

## APCu Functions

Mini provides automatic APCu polyfills when the native extension is unavailable. All standard APCu functions work regardless of whether APCu is installed.

### Basic Operations

```php
apcu_store(string $key, mixed $value, int $ttl = 0): bool
apcu_fetch(string $key, bool &$success = null): mixed
apcu_exists(string|array $keys): bool|array
apcu_delete(string|array $key): bool|array
apcu_clear_cache(): bool
```

### Atomic Operations

```php
apcu_entry(string $key, callable $generator, int $ttl = 0): mixed  # Fetch-or-compute
apcu_add(string $key, mixed $value, int $ttl = 0): bool            # Store if not exists
apcu_cas(string $key, int $old, int $new): bool                    # Compare-and-swap
apcu_inc(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false
apcu_dec(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false
```

### Information

```php
apcu_cache_info(bool $limited = false): array|false  # Cache statistics
apcu_sma_info(bool $limited = false): array|false    # Shared memory info
apcu_key_info(string $key): ?array                   # Key metadata
apcu_enabled(): bool                                 # Check availability
```

### Usage Examples

```php
// Simple caching
apcu_store('config', $config, ttl: 300);
$config = apcu_fetch('config', $found);

// Fetch-or-compute pattern (atomic)
$data = apcu_entry('expensive:calculation', function() {
    return performExpensiveCalculation();
}, ttl: 60);

// Atomic counter
apcu_inc('page:views', 1);
$views = apcu_fetch('page:views');

// Conditional update
if (apcu_cas('counter', 5, 10)) {
    echo "Updated counter from 5 to 10";
}
```

### Driver Selection

When native APCu is not installed, Mini automatically selects the best available driver:

1. **Swoole\Table** - Coroutine-safe shared memory (requires Swoole)
2. **SQLite** - Persistent storage in `/dev/shm` (requires pdo_sqlite)
3. **Array** - Process-scoped fallback (no persistence)

**Configuration (optional):**
```bash
# .env
MINI_APCU_SQLITE_PATH=/custom/path.sqlite         # Custom SQLite path
MINI_APCU_SWOOLE_SIZE=4096                        # Swoole table size
MINI_APCU_SWOOLE_VALUE_SIZE=4096                  # Max value size
```

See `README.md` (APCu Polyfill section) for complete documentation.

## Performance Tips

1. **Use APCu for L1 caching** - Sub-millisecond operations via `apcu_entry()`
2. **Use direct SQL for simple queries** - Skip the query builder when not needed
3. **Cache expensive operations** - Use `cache()` for computed results
4. **Lazy initialization** - Services only load when used
5. **File-based routing** - No route compilation needed
6. **Request-scoped caching** - Database, cache, logger instances reused within request

## Common Patterns

Route files return handlers or responses — never `echo` or `header()` directly.

### Form Handling

```php
// _routes/users/create.php
<?php return function (): mixed {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        db()->insert('users', [
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
        ]);
        throw new mini\Router\Redirect((string)url('users'));
    }
    return new mini\Http\Message\HtmlResponse(
        render('form.php', ['title' => t('Create User')])
    );
};
```

### API Endpoints

```php
// _routes/api/users.php — arrays returned from a Closure convert to JSON;
// exceptions convert to error responses via the exception converter registry
<?php return fn() => db()->query('SELECT * FROM users')->all();
```

### Protected Routes

```php
// _routes/admin/users.php
<?php
return function () {
    auth()->requireLogin()->requireRole('admin');
    return new mini\Http\Message\HtmlResponse(
        render('templates/admin/users.php', [
            'users' => db()->query('SELECT * FROM users'),  // lazy; template iterates
        ])
    );
};
```

## See Also

- **README.md** - Getting started and philosophy
- **PATTERNS.md** - Advanced patterns (service overrides, middleware, response processing)
- **CLAUDE.md** - Development guide for Claude Code
