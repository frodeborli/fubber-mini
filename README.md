# Mini - Zero-Dependency PHP Framework

**Get started in 4 commands:**

```bash
composer require fubber/mini
mkdir _routes
echo '<?php return date("c");' > _routes/time.php
composer exec mini serve
```

Visit `http://localhost/time` - you're running.

---

**Mini isn't minimal because it lacks features - it's minimal because it lacks overhead.**

Traditional frameworks bootstrap in 50-100ms, loading megabytes of PHP classes to reinvent what PHP's C engine already does. Mini bootstraps in ~2ms by leveraging PHP's engine-level capabilities instead of fighting them.

**Monolithic by design, lazy by execution.** Mini includes full-stack capabilities (ORM, auth, i18n, templates, validation), but they only load when you touch them. Start with a single file returning a timestamp, scale to enterprise complexity without changing frameworks.

**Zero required dependencies** enables safe sub-application mounting - run PSR-15 compliant apps (Slim, Mezzio, etc.) side-by-side without dependency conflicts. Each mounted app can have its own `composer.json` and dependency versions.

## Philosophy: Engine-Native, Not Userland-Native

**We use PHP's C-level engine, not userland reimplementations.** Modern frameworks reimplement locale handling, date formatting, and number formatting in PHP code. Mini uses PHP's `intl` extension (ICU library in C) and native functions.

### Engine-Level Performance

**Internationalization:**
```php
// Mini: Use PHP's intl extension (C-level ICU)
\Locale::setDefault('de_DE');           // Sets locale for entire engine
echo fmt()->currency(19.99, 'EUR');     // "19,99 €" - formatted by ICU in C
echo t("Hello, {name}!", ['name' => 'World']);  // MessageFormatter in C

// Framework approach: Load massive translation arrays, parse ICU in PHP
$translator->trans('messages.welcome', ['name' => 'World'], 'en_US');
```

**Routing:**
```php
// Mini: File system IS the routing table (OS-cached, instant lookup)
_routes/users/_.php  // Wildcard matches any ID, captured in $_GET[0]

// Framework approach: Parse regex routes on every request (slow)
$router->addRoute('GET', '/users/{id}', [UserController::class, 'show']);
```

**Templates:**
```php
// Mini: PHP IS the template language (no parsing overhead)
<?= h($user->name) ?>  // Direct output buffering, closure-based inheritance

// Framework approach: Parse string templates into PHP (Blade, Twig)
{{ $user->name }}
```

### What We Use (And Why)

**Request/Response:**
- `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION` - Request-scoped proxies (fiber-safe for future async)
- `header()`, `http_response_code()`, `echo` - Direct output control in SAPI environments
- `\Locale::setDefault()`, `date_default_timezone_set()` - Engine-level configuration

**Helpers when they genuinely simplify:**
```php
$users = db()->query("SELECT * FROM users WHERE active = ?", [1])->fetchAll();
echo render('user/profile', ['user' => $user]);
echo t("Hello, {name}!", ['name' => 'World']);
session();  // Starts session if needed
```

### Lazy-Loading Architecture

**All features exist, but nothing loads until touched:**
```php
mail();        // Loads symfony/mailer only if installed
table(User::class);  // Loads ORM only when used
auth()->check();     // Loads authentication system on demand
```

This "soft dependency" pattern means:
- A "Hello World" app uses ~300KB of memory
- Full-stack enterprise app uses what it needs
- No bootstrap penalty for unused features

**Configuration over code.** Override framework services via config files, not subclassing:

- Create `_config/Psr/Log/LoggerInterface.php` to return your logger
- Create `_config/PDO.php` to return your database connection
- Framework loads these automatically - no service registration needed

## Two Paradigms: Choose What Fits

Mini supports **both PSR-7 standard patterns and native PHP patterns**. You can mix them in the same application.

### PSR-7 Pattern (Standards-Based)

Use PSR-7 `ServerRequestInterface` and `ResponseInterface` for framework-agnostic code:

```php
// _routes/api/users.php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

return function(ServerRequestInterface $request): ResponseInterface {
    $id = $request->getQueryParams()['id'] ?? null;

    $response = response();
    $response->getBody()->write(json_encode(['user' => $id]));
    return $response->withHeader('Content-Type', 'application/json');
};
```

**When to use:** Libraries, packages, sub-applications (Slim, Symfony), testability, framework portability.

### Native PHP Pattern (Direct)

Use PHP's native request/response mechanisms directly:

```php
// _routes/api/users.php
$id = $_GET['id'] ?? null;

header('Content-Type: application/json');
echo json_encode(['user' => $id]);
```

**When to use:** Simple applications, SAPI environments (FPM, mod_php, RoadRunner), rapid prototyping.

### How They Coexist

Mini provides **request-scoped proxies** for `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION` that interact with the PSR-7 `ServerRequest`:

- **In SAPI environments** (FPM, CGI, mod_php): Proxies read from PHP's native superglobals
- **In non-SAPI environments** (Swoole, ReactPHP, phasync with Fibers): Proxies read from the PSR-7 request object
- **Controllers can return PSR-7 responses OR echo output** - Mini handles both
- **Use `header()` in SAPI** or **`mini\header()` in non-SAPI** environments

This design enables:
- **Sub-application mounting:** Mount PSR-15 compliant frameworks (Slim, Mezzio, etc.) without dependency conflicts (see "Mounting Sub-Applications")
- **Gradual complexity:** Start with `echo` and `$_GET`, grow into PSR-7 and controllers as needs evolve
- **Future async support:** Native PHP patterns will work in Fiber-based async environments (Swoole, ReactPHP)

## Installation

```bash
composer require fubber/mini
```

## Quick Start

Create the entry point:

```php
// html/index.php
<?php
require '../vendor/autoload.php';
mini\router();
```

Create your first route:

```php
// _routes/index.php
<?php
echo "<h1>Hello, World!</h1>";
```

Start the development server:

```bash
vendor/bin/mini serve
```

Visit `http://localhost` - you're running!

## Routing: File System as Routing Table

**Mini uses the file system as its routing table.** No regex parsing, no route compilation, no routing cache - just OS-level file lookups (microseconds, cached by the kernel).

### File-Based Routing

Routes map directly to PHP files in `_routes/`:

```
_routes/index.php        → /
_routes/users.php        → /users
_routes/api/posts.php    → /api/posts
```

### Wildcard Routing with `_`

Use `_` as a filename or directory name to match any single path segment:

```php
// _routes/users/_.php - Matches /users/123, /users/john, /users/anything
$userId = $_GET[0];  // Captured value: "123", "john", "anything"
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
echo json_encode($user);
```

```php
// _routes/users/_/posts/_.php - Matches /users/{userId}/posts/{postId}
$userId = $_GET[0];   // First wildcard segment
$postId = $_GET[1];   // Second wildcard segment
$post = db()->queryOne("SELECT * FROM posts WHERE id = ? AND user_id = ?", [$postId, $userId]);
echo json_encode($post);
```

**Wildcard behavior:**
- `_.php` matches any single segment (e.g., `/users/123`)
- `_/index.php` matches any single segment with trailing slash (e.g., `/users/123/`)
- Exact matches take precedence over wildcards
- Captured values stored in `$_GET[0]`, `$_GET[1]`, etc. (left to right)
- Wildcards match single segments only (won't match across `/`)

**Examples:**
```
URL: /users/123           → _routes/users/_.php          ($_GET[0] = "123")
URL: /users/123/          → _routes/users/_/index.php    ($_GET[0] = "123")
URL: /users/john/posts/5  → _routes/users/_/posts/_.php  ($_GET[0] = "john", $_GET[1] = "5")
```

### Trailing Slash Redirects

The router automatically redirects to ensure consistency:
- If only `_.php` exists: `/users/123/` → 301 redirect to `/users/123`
- If only `_/index.php` exists: `/users/123` → 301 redirect to `/users/123/`
- If both exist: Each URL serves its respective file (no redirect)

**What route files can return:**
- Nothing (echo output directly)
- PSR-7 `ResponseInterface`
- Callable that returns PSR-7 response
- Controller instance with attributes
- PSR-15 `RequestHandlerInterface`

```php
// _routes/users.php - Direct output (native PHP)
header('Content-Type: application/json');
echo json_encode(['users' => db()->query("SELECT * FROM users")->fetchAll()]);
```

```php
// _routes/users.php - PSR-7 response
return response()->json(['users' => db()->query("SELECT * FROM users")->fetchAll()]);
```

### Controller-Based Routing

**File-based routing doesn't mean "no OOP."** Use `__DEFAULT__.php` to mount controllers with attribute-based routing:

```php
// _routes/users/__DEFAULT__.php - Handles /users/*
use mini\Controller\AbstractController;
use mini\Controller\Attributes\{GET, POST, PUT, DELETE};

return new class extends AbstractController {
    #[GET('/')]
    public function index(): array
    {
        return db()->query("SELECT * FROM users")->fetchAll();
    }

    #[GET('/{id}/')]
    public function show(int $id): array
    {
        $user = db()->query("SELECT * FROM users WHERE id = ?", [$id])->fetch();
        if (!$user) throw new \mini\Exceptions\ResourceNotFoundException();
        return $user;
    }

    #[POST('/')]
    public function create(): array
    {
        $id = db()->insert('users', $_POST);
        return ['id' => $id, 'message' => 'Created'];
    }

    #[PUT('/{id}/')]
    public function update(int $id): array
    {
        db()->update('users', $_POST, 'id = ?', [$id]);
        return ['message' => 'Updated'];
    }

    #[DELETE('/{id}/')]
    public function delete(int $id): ResponseInterface
    {
        db()->delete('users', 'id = ?', [$id]);
        return $this->empty(204);
    }
};
```

**Key benefits:**
- **Scoped routing:** `/users/123/` becomes `/{id}/` inside the controller
- **Type-aware parameters:** `int $id` automatically extracts and casts URL parameter
- **Converter integration:** Return arrays, strings, or domain objects - auto-converted to JSON/text
- **Attribute-based:** Routes declared with method attributes (no manual registration)

**URL mapping:**
- `GET /users/` → `index()` → returns array → JSON response
- `GET /users/123/` → `show(int $id)` → `$id = 123` (typed!)
- `POST /users/` → `create()` → uses `$_POST` directly
- `DELETE /users/123/` → `delete(int $id)` → returns 204 No Content

**When to use controllers:**
- Multiple related endpoints (CRUD operations)
- Type-safe URL parameters
- Return value conversion (arrays → JSON)
- Clean, declarative routing

### Exception Handling

**Mini uses transport-agnostic exceptions** that are mapped to appropriate responses by the dispatcher:

```php
// Throw domain exceptions - dispatcher handles HTTP mapping
throw new \mini\Exceptions\ResourceNotFoundException('User not found');        // → 404
throw new \mini\Exceptions\AccessDeniedException('Login required');           // → 401/403
throw new \mini\Exceptions\BadRequestException('Invalid email format');       // → 400
```

**Debug mode shows detailed error pages** with stack traces. In production, clean error pages are shown.

**Custom error pages:** Create `_errors/404.php`, `_errors/500.php`, etc. to override default error pages. The exception is available as `$exception`.

**For complete coverage** of routing, error handling, converters, and web app patterns, see **[docs/web-apps.md](docs/web-apps.md)**.

### Dynamic Routes with `__DEFAULT__.php`

Handle dynamic segments with pattern matching:

```php
// _routes/blog/__DEFAULT__.php
return [
    '/' => 'index.php',                              // /blog/
    '/{slug}' => fn($slug) => "post.php?slug=$slug", // /blog/my-post
    '/{year}/{month}' => 'archive.php',              // /blog/2025/11
];
```

## Mounting Sub-Applications

**Mini's zero-dependency design enables mounting entire frameworks** as sub-applications without dependency conflicts. Each sub-app can have its own `vendor/` directory with different dependency versions.

### Example: Mount a Slim 4 Application

```php
// _routes/api/__DEFAULT__.php
require_once __DIR__ . '/api-app/vendor/autoload.php';  // Slim's autoloader

use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Define Slim routes
$app->get('/users', function ($request, $response) {
    $response->getBody()->write(json_encode(['users' => []]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/users', function ($request, $response) {
    $data = $request->getParsedBody();
    // ... handle user creation
    return $response->withStatus(201);
});

// Return the Slim app (implements RequestHandlerInterface)
return $app;
```

**Project structure with mounted apps:**

```
project/
├── _routes/
│   ├── index.php              # Mini native route
│   ├── api/
│   │   ├── __DEFAULT__.php    # Mounts Slim app
│   │   └── api-app/           # Complete Slim application
│   │       ├── composer.json  # Slim's dependencies (guzzle 7.x)
│   │       └── vendor/        # Slim's vendor directory
│   └── admin/
│       ├── __DEFAULT__.php    # Mounts Symfony app
│       └── admin-app/         # Complete Symfony application
│           ├── composer.json  # Symfony's dependencies (guzzle 6.x)
│           └── vendor/        # Symfony's vendor directory
├── composer.json              # Mini (no dependencies!)
└── vendor/                    # Mini's vendor directory
```

### How It Works

1. **Mini has zero required dependencies** - only PSR interfaces (dev/suggest)
2. **Sub-apps are isolated** - each has its own `vendor/autoload.php`
3. **PSR-7 bridges everything** - Mini provides `ServerRequestInterface`, sub-apps return `ResponseInterface`
4. **No conflicts** - Slim can use `guzzlehttp/psr7:7.x`, Symfony can use `6.x`, no collision

### Supported Sub-Applications

Any framework/application that:
- Implements `Psr\Http\Server\RequestHandlerInterface` (PSR-15), OR
- Is a callable accepting `ServerRequestInterface` and returning `ResponseInterface` (PSR-7)

**Examples:**
- **Slim 4** - Native PSR-15 support
- **Mezzio** (formerly Zend Expressive) - Native PSR-15 support
- **Symfony** - Via PSR-15 adapters (e.g., `symfony/psr-http-message-bridge`)
- **Custom PSR-15 middleware stacks**
- **Any PSR-7/PSR-15 compliant application**

### Why This Matters

**Traditional monorepos fail when dependencies conflict.** With Mini:
- Marketing team uses Slim 4 with latest dependencies
- Support team maintains legacy Symfony 4 app with old dependencies
- API team writes new endpoints in Mini native code
- **All three run in one application** - no Docker, no microservices, no reverse proxy routing

## Database

Mini provides a thin wrapper over PDO with convenience methods:

```php
// Query methods
$users = db()->query("SELECT * FROM users WHERE active = ?", [1]);
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [123]);
$count = db()->queryField("SELECT COUNT(*) FROM users");

// Convenience methods
$userId = db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
$affected = db()->update('users', ['active' => 1], 'id = ?', [123]);
$affected = db()->delete('users', 'id = ?', [123]);

// Transactions
db()->transaction(function() {
    db()->insert('users', ['name' => 'John']);
    db()->insert('activity_log', ['action' => 'user_created']);
});
```

**See [src/Database/README.md](src/Database/README.md) for complete documentation.**

## Tables - ORM with Repository Pattern (Optional)

Define POPOs (Plain Old PHP Objects) with attributes, managed by repositories.

Like Entity Framework's POCO (Plain Old CLR Object) approach, entities are plain classes without database logic:

```php
use mini\Tables\Attributes\{Entity, Key, Generated, VarcharColumn};

#[Entity(table: 'users')]
class User {
    #[Key] #[Generated]
    public ?int $id = null;

    #[VarcharColumn(100)]
    public string $username;

    public string $email;
}

// Find by primary key
$user = table(User::class)->find($id);

// Query with conditions
$admins = table(User::class)->where('role = ?', ['admin'])->all();

// Save
$user = new User();
$user->username = 'john';
$user->email = 'john@example.com';
table(User::class)->save($user);
```

## Internationalization

**Best Practice:** Use `t()` and `fmt()` everywhere to make your app translatable from day one.

```php
// Always use t() for user-facing text (even in English)
echo t("Hello, {name}!", ['name' => $user->name]);
echo t("You have {count, plural, =0{no messages} one{# message} other{# messages}}",
    ['count' => $messageCount]);

// Always use fmt() for numbers, dates, and currency
echo fmt()->currency($price, 'USD');     // Locale-aware: "$1,234.56" or "1 234,56 $"
echo fmt()->dateShort($order->date);     // "11/15/2025" or "15.11.2025"
echo fmt()->number($revenue);            // "1,234,567.89" or "1.234.567,89"
```

### Per-Request Locale/Timezone

Set locale and timezone per request based on user preferences:

```php
// bootstrap.php (autoloaded via composer.json)
use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Get user's preferred locale from session, cookie, or Accept-Language header
    $locale = $_SESSION['locale'] ?? $_COOKIE['locale'] ?? 'en_US';
    $timezone = $_SESSION['timezone'] ?? 'UTC';

    // Set for this request
    \Locale::setDefault($locale);
    date_default_timezone_set($timezone);
});
```

Translation files in `_translations/`:

```php
// _translations/de_DE.php
return [
    'Hello, {name}!' => 'Hallo, {name}!',
    'You have {count, plural, =0{no messages} one{# message} other{# messages}}' =>
        'Sie haben {count, plural, =0{keine Nachrichten} one{# Nachricht} other{# Nachrichten}}',
];
```

**See [src/I18n/README.md](src/I18n/README.md) for complete documentation.**

## Authentication

Simple authentication with pluggable user providers:

```php
// Check authentication
if (!auth()->check()) {
    redirect('/login');
}

// Require login (throws exception if not authenticated)
mini\require_login();

// Role-based access
mini\require_role('admin');

// Login
if (auth()->login($username, $password)) {
    redirect('/dashboard');
}

// Logout
auth()->logout();
```

## Templates

Pure PHP templates with inheritance support:

```php
// Render a template
echo render('user/profile', ['user' => $user]);
```

Templates support multi-level inheritance:

```php
// _views/user/profile.php
<?php $extend('layouts/main.php'); ?>
<?php $block('title', 'User Profile'); ?>
<?php $block('content'); ?>
    <h1><?= htmlspecialchars($user->name) ?></h1>
    <p><?= t("Member since {date}", ['date' => fmt()->dateShort($user->created)]) ?></p>
<?php $end(); ?>

// _views/layouts/main.php
<!DOCTYPE html>
<html>
<head><title><?php $show('title', 'Untitled'); ?></title></head>
<body><?php $show('content'); ?></body>
</html>
```

**See [src/Template/README.md](src/Template/README.md) for complete documentation.**

## Lifecycle Hooks

Hook into application lifecycle via phase transitions:

```php
use mini\Mini;
use mini\Phase;

// Before Ready phase (authentication, CORS, rate limiting)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Check authentication
    if (!isset($_SESSION['user_id']) && str_starts_with($_SERVER['REQUEST_URI'], '/admin')) {
        http_response_code(401);
        exit;
    }
});

// After Ready phase (output buffering, response processing)
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    ob_start(function($buffer) {
        // Minify HTML
        return preg_replace('/\s+/', ' ', $buffer);
    });
});
```

## Configuration

### Environment Variables (.env)

Create a `.env` file in your project root for environment-specific configuration:

```bash
# .env - Not committed to version control

# Database (MySQL/PostgreSQL)
DATABASE_DSN="mysql:host=localhost;dbname=myapp;charset=utf8mb4"
DATABASE_USER="myapp_user"
DATABASE_PASS="secret_password"

# Or use SQLite (default if no config)
# DATABASE_DSN="sqlite:/path/to/database.sqlite3"

# Mini framework settings
MINI_LOCALE="en_US"
MINI_TIMEZONE="America/New_York"
DEBUG=true

# Application salt for security (generate with: openssl rand -hex 32)
MINI_SALT="your-64-character-random-hex-string-here"

# Optional: Custom paths
MINI_ROOT="/path/to/project"
MINI_DOC_ROOT="/path/to/project/html"
```

**Load environment variables** with vlucas/phpdotenv or similar:

```bash
composer require vlucas/phpdotenv
```

```php
// bootstrap.php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
```

### Bootstrap File (bootstrap.php)

Create a bootstrap file for application initialization, autoloaded via composer.json:

```json
{
    "autoload": {
        "files": ["bootstrap.php"]
    }
}
```

```php
// bootstrap.php - Runs before every request
require __DIR__ . '/vendor/autoload.php';

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Register lifecycle hooks
use mini\Mini;
use mini\Phase;

// Set locale/timezone per request from user session
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    session();  // Start session

    // Get user's preferred locale/timezone
    $locale = $_SESSION['locale'] ?? $_ENV['MINI_LOCALE'] ?? 'en_US';
    $timezone = $_SESSION['timezone'] ?? $_ENV['MINI_TIMEZONE'] ?? 'UTC';

    \Locale::setDefault($locale);
    date_default_timezone_set($timezone);
});

// Global error handler (optional)
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
```

### Database Configuration

Create `_config/PDO.php` to configure your database:

```php
// _config/PDO.php
$dsn = $_ENV['DATABASE_DSN'] ?? 'sqlite:' . __DIR__ . '/../_database.sqlite3';
$user = $_ENV['DATABASE_USER'] ?? null;
$pass = $_ENV['DATABASE_PASS'] ?? null;

return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
```

**Don't forget to run `composer dump-autoload`** after modifying composer.json!

## Directory Structure

Directories starting with `_` are not web-accessible:

```
project/
├── .env               # Environment variables (not committed)
├── bootstrap.php      # Application initialization (autoloaded)
├── composer.json      # Dependencies and autoload configuration
├── _routes/           # Route handlers
├── _views/            # Templates
├── _config/           # Service configuration
├── _translations/     # Translation files
├── html/              # Document root (web-accessible)
│   ├── index.php      # Entry point
│   └── assets/        # CSS, JS, images
└── vendor/            # Composer dependencies
```

## Development Server

```bash
vendor/bin/mini serve                    # http://localhost
vendor/bin/mini serve --port 3000        # Custom port
vendor/bin/mini serve --host 0.0.0.0     # Bind to all interfaces
```

## Documentation

### Essential Guides

- [PATTERNS.md](PATTERNS.md) - Service overrides, middleware patterns, output buffering
- [REFERENCE.md](REFERENCE.md) - Complete API reference
- [CHANGE-LOG.md](CHANGE-LOG.md) - Breaking changes (Mini is in active development)

### Feature Documentation

Detailed documentation for each framework feature:

- **[src/Database/README.md](src/Database/README.md)** - PDO abstraction, queries, transactions, configuration
- **[src/Template/README.md](src/Template/README.md)** - Template rendering, inheritance, blocks, partials
- **[src/I18n/README.md](src/I18n/README.md)** - Translations, ICU MessageFormat, locale formatting
- **[src/Tables/README.md](src/Tables/README.md)** - ORM, repositories, entities, query building
- **[src/Auth/README.md](src/Auth/README.md)** - Authentication, user providers, sessions, JWT
- **[src/Cache/README.md](src/Cache/README.md)** - PSR-16 caching, APCu, SQLite, filesystem
- **[src/Mailer/README.md](src/Mailer/README.md)** - Email sending via Symfony Mailer
- **[src/Validator/README.md](src/Validator/README.md)** - JSON Schema validation, attributes
- **[src/Logger/README.md](src/Logger/README.md)** - PSR-3 logging, custom loggers
- **[src/Router/README.md](src/Router/README.md)** - File-based routing, dynamic routes, PSR-15
- **[src/Http/README.md](src/Http/README.md)** - PSR-7 HTTP messages, error handling
- **[src/Dispatcher/README.md](src/Dispatcher/README.md)** - Request lifecycle, exception handling
- **[src/Converter/README.md](src/Converter/README.md)** - Type conversion for dependency injection
- **[src/Util/README.md](src/Util/README.md)** - Utility classes (Path, IdentityMap, QueryParser, etc.)
- **[src/Hooks/README.md](src/Hooks/README.md)** - Event system, phase lifecycle, state machines
- **[src/UUID/README.md](src/UUID/README.md)** - UUID v4/v7 generation
- **[src/Metadata/README.md](src/Metadata/README.md)** - JSON Schema annotations via attributes

### CLI Documentation Browser

```bash
vendor/bin/mini docs --help              # See available commands
vendor/bin/mini docs mini                # Browse mini namespace
vendor/bin/mini docs "mini\Mini"         # Class documentation
```

## License

MIT License - see LICENSE file.
