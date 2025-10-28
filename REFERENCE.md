# Mini Framework - API Reference

Quick reference for Mini framework functions and classes.

## Core Functions

### Framework Bootstrap

```php
bootstrap(): void       # Initialize framework (error handlers, output buffering)
router(): void          # Handle routing (calls bootstrap() internally)
```

### Translation

```php
t(string $text, array $vars = []): Translatable  # Create translatable text
translator(): Translator                          # Get translator instance
```

### HTML & Output

```php
h(string $str): string                            # HTML escape
render(string $template, array $vars = []): string  # Render template from _views/
```

### Template Inheritance

Inside templates, these helpers are available for layout inheritance:

```php
$extend(string $layout)              # Extend parent layout
$start(string $blockName)            # Start capturing block
$end()                               # End block capture
$set(string $name, string $value)    # Set block to simple value
$block(string $name, string $default = '')  # Output block with default
$partial(string $file, array $vars = [])    # Include partial template
```

**Example:**
```php
// Child template
<?php $extend('layout.php'); ?>
<?php $set('title', 'My Page'); ?>
<?php $start('content'); ?><p>Content</p><?php $end(); ?>

// Parent layout
<html><head><title><?php $block('title', 'Untitled'); ?></title></head>
<body><?php $block('content'); ?></body></html>

// With partial
<?= $partial('_user-card.php', ['user' => $currentUser]) ?>
```

### URL Generation

```php
url(string $path = '', array $query = []): string  # Generate URL
redirect(string $url, int $statusCode = 302): void # Redirect
current_url(): string                              # Get current URL
```

### Session & Flash Messages

```php
session(): bool                               # Safe session initialization
flash_set(string $type, string $message): void  # Set flash message
flash_get(): array                             # Get and clear flash messages
```

### Database

```php
db(): DatabaseInterface  # Get request-scoped database instance
```

**DatabaseInterface Methods:**
```php
query(string $sql, array $params = []): array          # All rows
queryOne(string $sql, array $params = []): ?array      # First row or null
queryField(string $sql, array $params = []): mixed     # First field of first row
queryColumn(string $sql, array $params = []): array    # First column as array
exec(string $sql, array $params = []): bool|int        # Execute (returns last insert ID or true)
lastInsertId(): ?string                                 # Get last insert ID
tableExists(string $tableName): bool                   # Check if table exists
transaction(\Closure $task): mixed                      # Run closure in transaction
```

### Tables (Query Builder)

```php
table(string $name): Repository  # Get table repository
```

**Repository Methods:**
```php
eq(string $field, mixed $value): Repository       # WHERE field = value
gte(string $field, mixed $value): Repository      # WHERE field >= value
lte(string $field, mixed $value): Repository      # WHERE field <= value
gt(string $field, mixed $value): Repository       # WHERE field > value
lt(string $field, mixed $value): Repository       # WHERE field < value
in(string $field, array $values): Repository      # WHERE field IN (...)
like(string $field, string $pattern): Repository  # WHERE field LIKE pattern
order(string $field, string $direction = 'asc'): Repository  # ORDER BY
limit(int $limit): Repository                     # LIMIT
offset(int $offset): Repository                   # OFFSET
all(): array                                      # Fetch all results
first(): ?object                                  # Fetch first result
count(): int                                      # Count results
page(int $page, int $perPage = 20): array        # Paginated results
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
Fmt::dateShort(\DateTimeInterface $date): string
Fmt::dateLong(\DateTimeInterface $date): string
Fmt::timeShort(\DateTimeInterface $time): string
Fmt::dateTimeShort(\DateTimeInterface $dt): string
Fmt::dateTimeLong(\DateTimeInterface $dt): string
Fmt::number(float|int $number, int $decimals = 0): string
Fmt::percent(float $ratio, int $decimals = 0): string
Fmt::fileSize(int $bytes): string
```

### Authentication

```php
setupAuth(\Closure $factory): void  # Register auth implementation
auth(): ?AuthInterface              # Get auth instance
is_logged_in(): bool                # Check if user is authenticated
require_login(): void               # Require authentication (throws 401)
require_role(string $role): void    # Require specific role (throws 403)
```

**AuthInterface (implement this):**
```php
interface AuthInterface {
    public function isAuthenticated(): bool;
    public function getUserId(): mixed;
    public function hasRole(string $role): bool;
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
render('form.php', ['nonce' => $nonce]);

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
    public readonly PathsRegistry $paths;      # Path registries
    public readonly bool $debug;               # Debug mode
    public readonly string $locale;            # Default locale
    public readonly string $timezone;          # Default timezone
    public readonly string $defaultLanguage;   # Default language
    public readonly string $salt;              # Cryptographic salt (auto-generated or MINI_SALT)

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

```php
throw new Http\NotFoundException($message);        # 404
throw new Http\AccessDeniedException($message);    # 401/403
throw new Http\BadRequestException($message);      # 400
throw new Http\HttpException($code, $message);     # Custom code
```

## Routing

### File-Based Routes

Files in `_routes/` directory map to URLs:

```
_routes/index.php              → /
_routes/users.php              → /users
_routes/api/posts.php          → /api/posts
```

### Pattern Routes

In `_config/routes.php`:

```php
return [
    "/users/{id:\d+}" => fn($id) => "_routes/users/detail.php?id={$id}",
    "/posts/{slug}" => fn($slug) => "_routes/posts/detail.php?slug={$slug}"
];
```

### Directory Routes

In `_routes/api/_routes.php`:

```php
return [
    "/api/users" => fn() => "_routes/api/users.php",
    "/api/posts/{id}" => fn($id) => "_routes/api/posts/detail.php?id={$id}"
];
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

- `bootstrap.php` - Application initialization
- `routes.php` - Pattern-based routes
- `PDO.php` - PDO factory override
- `Psr/Log/LoggerInterface.php` - Logger override
- `Psr/SimpleCache/CacheInterface.php` - Cache override

## CLI Commands

```bash
composer exec mini serve                         # Start development server
composer exec mini serve --host 0.0.0.0 --port 3000  # Custom host/port
composer exec mini migrations                    # Run pending migrations
composer exec mini translations                  # Validate translations
composer exec mini translations add-missing      # Add missing translation strings
composer exec mini translations add-language nb  # Create new language
composer exec mini translations remove-orphans   # Remove unused translations
composer exec mini benchmark                     # Run performance benchmarks
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

## Testing Helpers

```php
function test(string $description, callable $test): void {
    try {
        $test();
        echo "✓ {$description}\n";
    } catch (\Exception $e) {
        echo "✗ {$description}\n";
        echo "  Error: {$e->getMessage()}\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new \Exception($message ?: "Expected != Actual");
    }
}
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

Override framework services in `app/bootstrap.php` (autoloaded via composer):

```php
// composer.json
{
    "autoload": {
        "files": ["app/bootstrap.php"]
    }
}
```

```php
// app/bootstrap.php
use mini\Mini;
use mini\Lifetime;
use Psr\Log\LoggerInterface;

Mini::$mini->addService(LoggerInterface::class, Lifetime::Singleton, function() {
    return new \Monolog\Logger('app');
});
```

See `PATTERNS.md` for detailed examples.

## Performance Tips

1. **Use direct SQL for simple queries** - Skip the query builder when not needed
2. **Cache expensive operations** - Use `cache()` for computed results
3. **Lazy initialization** - Services only load when used
4. **File-based routing** - No route compilation needed
5. **Request-scoped caching** - Database, cache, logger instances reused within request

## Common Patterns

### Form Handling

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';

    // Validate, save, redirect
    db()->exec('INSERT INTO users (username, email) VALUES (?, ?)', [$username, $email]);
    redirect(url('users'));
}

echo render('form.php', ['title' => t('Create User')]);
```

### API Endpoints

```php
header('Content-Type: application/json');

try {
    $users = db()->query('SELECT * FROM users')->fetchAll();
    echo json_encode($users);
} catch (\Exception $e) {
    log()->error('Failed to fetch users', ['exception' => $e]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
```

### Protected Routes

```php
require_login();
require_role('admin');

$users = db()->query('SELECT * FROM users')->fetchAll();
echo render('templates/admin/users.php', ['users' => $users]);
```

## See Also

- **README.md** - Getting started and philosophy
- **PATTERNS.md** - Advanced patterns (service overrides, middleware, response processing)
- **CLAUDE.md** - Development guide for Claude Code
