# Mini - PHP Micro-Framework

Minimalist PHP framework that embraces native PHP instead of hiding it. Use `$_GET`, `$_POST`, `$_SESSION`, `header()` directly. Mini provides optional helpers when they genuinely simplify common tasks.

## Philosophy

**We don't hide PHP - we use it properly.** Modern frameworks abstract away PHP's built-in functionality. Mini takes the opposite approach: use PHP's native features the way they were designed.

**Native PHP patterns we embrace:**

- `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION` - Request-scoped variables (not "superglobals")
- `\Locale::setDefault()` - Set user locale, respected by PHP's native functions
- `date_default_timezone_set()` - Set user timezone, respected throughout PHP
- `header()`, `http_response_code()`, `echo` - Direct output control
- Output buffering - Transform responses without forcing abstractions

**Request scope, not global scope.** Variables like `$_GET` and `$_POST` are called "superglobals," but they're really request-scoped in traditional PHP (FPM, CGI, mod_php, RoadRunner). Mini maintains this pattern:

- `db()` returns a request-scoped database connection, not a shared global
- When we add support for long-running environments (Swoole, ReactPHP, phasync), we'll context-switch these variables per request using Fibers/Coroutines
- Static class variables are avoided unless truly intended as application-wide singletons
- Everything is designed for concurrent request handling from day one

**Thin wrappers, not abstractions.** Mini provides helpers for common tasks without hiding what's underneath:

```php
// Modern framework - abstraction
$request->query->get('id');
$response->json(['status' => 'ok']);
$container->get(LocaleService::class)->setLocale('de_DE');

// Mini - native PHP with optional helpers
$id = $_GET['id'];
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
\Locale::setDefault('de_DE');

// Mini helpers when they add value
$users = db()->query("SELECT * FROM users WHERE active = ?", [1])->fetchAll();
echo t("Hello, {name}!", ['name' => 'World']);
echo Fmt::currency(19.99, 'EUR');  // Respects current locale
```

**PSR support without forcing it.** We support PSR-7 `ServerRequestInterface` and `ResponseInterface` for those who prefer that pattern, but we don't force abstractions on you. Use what makes sense for your application.

**Configuration over code.** Override framework services via config files, not subclassing:

- Create `_config/Psr/Log/LoggerInterface.php` to return your logger
- Create `_config/PDO.php` to return your database connection
- Framework loads these automatically - no service registration needed

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

## File-Based Routing

Routes map directly to PHP files in `_routes/`:

```
_routes/index.php        → /
_routes/users.php        → /users
_routes/api/posts.php    → /api/posts
```

For dynamic routes, create `__DEFAULT__.php`:

```php
// _routes/blog/__DEFAULT__.php
return [
    '/{slug}' => fn($slug) => "post.php?slug=$slug",
];
```

## Database

Direct SQL with helpers for common operations:

```php
// Raw queries with parameter binding
$users = db()->query("SELECT * FROM users WHERE active = ?", [1])->fetchAll();

// Helpers for CRUD
db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
db()->update('users', ['active' => 0], 'id = ?', [123]);
db()->delete('users', 'id = ?', [123]);

// Or use PDO directly
$pdo = db();  // Returns PDO instance
$stmt = $pdo->prepare("SELECT * FROM users");
```

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

Translation and locale-specific formatting using ICU MessageFormat:

```php
// Set locale (just use PHP's native function)
\Locale::setDefault('de_DE');
date_default_timezone_set('Europe/Berlin');

// Translation with interpolation
echo t("Hello, {name}!", ['name' => 'World']);

// Pluralization
echo t("{count, plural, =0{no items} one{# item} other{# items}}", ['count' => 5]);

// Locale-aware formatting
echo Fmt::currency(19.99, 'EUR');  // "19,99 €" in de_DE
echo Fmt::dateShort(new DateTime());  // "29.10.2025" in de_DE
```

Translation files in `_translations/`:

```php
// _translations/de_DE.php
return [
    'Hello, {name}!' => 'Hallo, {name}!',
];
```

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

Simple template rendering with inheritance:

```php
// Render a template
echo render('user/profile.php', ['user' => $user]);
```

Templates support inheritance:

```php
// _views/user/profile.php
<?php $extend('layouts/main.php'); ?>
<?php $block('title', 'User Profile'); ?>
<?php $block('content'); ?>
    <h1><?= h($user->name) ?></h1>
<?php $end(); ?>

// _views/layouts/main.php
<!DOCTYPE html>
<html>
<head><title><?php $show('title', 'Untitled'); ?></title></head>
<body><?php $show('content'); ?></body>
</html>
```

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

## Directory Structure

Directories starting with `_` are not web-accessible:

```
project/
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

Essential guides:

- [PATTERNS.md](PATTERNS.md) - Service overrides, middleware patterns, output buffering
- [REFERENCE.md](REFERENCE.md) - Complete API reference
- [CHANGE-LOG.md](CHANGE-LOG.md) - Breaking changes (Mini is in active development)

CLI documentation browser:

```bash
vendor/bin/mini docs --help              # See available commands
vendor/bin/mini docs mini                # Browse mini namespace
vendor/bin/mini docs "mini\Mini"         # Class documentation
```

## License

MIT License - see LICENSE file.
