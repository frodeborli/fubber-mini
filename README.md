# Mini Framework

A PHP framework that stays close to native PHP. Complete with routing, database, i18n, caching, and migrations—without wrapping everything in layers of abstraction.

**Performance:** ~0.2-0.5ms per request on PHP's built-in server

## Why Mini?

We made a conscious decision to stay close to native PHP rather than building abstractions for the sake of abstractions. This means:

- **Use `$_POST` and `$_GET` directly** - No request object wrappers. PHP already handles request scope correctly.
- **Use `\Locale::setDefault()` directly** - No framework wrappers for what PHP does well.
- **Use `new \Collator()` directly** - Native PHP intl classes work fine.
- **Use PDO with light helpers** - Direct SQL when you need it, query builders when convenient.

This makes Mini fundamentally different from Laravel, Slim, or Symfony:
- **Runs much faster** - No overhead from unnecessary abstractions
- **Scales to thousands of routes** - File-based routing means no route compilation
- **Easy to add features** - Use Symfony packages or any PSR-compatible library
- **Shallow** - We don't wrap your code in dozens of functions and magic

**If you don't like this approach, pick a different framework.** We're not trying to be everything to everyone.

## Quick Start

### Installation

```bash
composer require fubber/mini
```

### Project Structure

```
your-app/
├── composer.json
├── vendor/
├── _config/                    # Config files (outside web root)
│   └── bootstrap.php          # Optional app initialization
├── _routes/                    # Route handlers (outside web root)
│   ├── index.php              # Handles /
│   ├── users.php              # Handles /users
│   └── api/
│       └── posts.php          # Handles /api/posts
├── _errors/                    # Error pages (outside web root)
│   ├── 404.php
│   ├── 401.php
│   └── 500.php
├── _views/                     # Template files (outside web root)
│   ├── layout.php             # Main layout
│   ├── users.php              # User list template
│   └── admin/
│       └── dashboard.php      # Admin dashboard template
├── _translations/              # Translation files (outside web root)
├── _migrations/                # Database migrations (outside web root)
├── _database.sqlite3          # Database (outside web root)
└── html/                      # Document root (web-accessible)
    ├── index.php              # Entry point
    └── assets/                # CSS, JS, images
```

**Security:** Everything except `html/` is outside the web root.

### Entry Point

Create `html/index.php`:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

mini\router();  // Handles routing and bootstraps framework
```

### Development Server

**Using Mini CLI (recommended):**

```bash
composer exec mini serve
# Starts server on http://127.0.0.1:8080

# Custom host and port:
composer exec mini serve --host 0.0.0.0 --port 3000
```

**Using PHP directly:**

```bash
# Run from project root
php -S 127.0.0.1:8080 -t ./html/
```

Visit: `http://127.0.0.1:8080`

**Note:** The dev server is for development only. Use Apache/Nginx in production.

## Routing

### File-Based Routing

URLs map directly to files in `_routes/`:

```
/              → _routes/index.php
/users         → _routes/users.php
/api/posts     → _routes/api/posts.php
```

**Example:** `_routes/api/posts.php`

```php
<?php
// No bootstrap() needed - router() already called it

header('Content-Type: application/json');

$posts = db()->query('SELECT * FROM posts ORDER BY created_at DESC')->fetchAll();

echo json_encode($posts);
```

### Pattern-Based Routing

For dynamic routes, create `_config/routes.php`:

```php
<?php
return [
    "/users/{id:\d+}" => fn($id) => "_routes/users/detail.php?id={$id}",
    "/posts/{slug}" => function(string $slug) {
        $postId = cache()->get("post_slug:{$slug}")
                  ?? db()->queryField('SELECT id FROM posts WHERE slug = ?', [$slug]);

        if (!$postId) {
            http_response_code(404);
            return "_errors/404.php";
        }

        return "_routes/posts/detail.php?id={$postId}";
    }
];
```

### Request Handling

Access request data directly with native PHP:

```php
<?php
// _routes/users/create.php

$nonce = csrf('create-user');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!$nonce->verify($_POST['__nonce__'])) {
        throw new mini\Http\BadRequestException('Invalid CSRF token');
    }

    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';

    db()->exec(
        'INSERT INTO users (username, email) VALUES (?, ?)',
        [$username, $email]
    );

    redirect(url('users'));
}

// Show form
echo render('users/create.php', [
    'title' => t('Create User'),
    'nonce' => $nonce
]);
```

**In _views/users/create.php:**
```php
<form method="POST">
    <?= $nonce ?>  <!-- Outputs hidden input field -->

    <label><?= t('Username') ?></label>
    <input name="username" required>

    <label><?= t('Email') ?></label>
    <input name="email" type="email" required>

    <button type="submit"><?= t('Create User') ?></button>
</form>
```

## Database

### Basic Queries

```php
// Get database instance (request-scoped)
$db = db();

// Fetch all rows
$users = $db->query('SELECT * FROM users WHERE active = 1')->fetchAll();

// Fetch one row
$user = $db->queryOne('SELECT * FROM users WHERE id = ?', [$userId]);

// Fetch single value
$count = $db->queryField('SELECT COUNT(*) FROM users');

// Fetch column
$ids = $db->queryColumn('SELECT id FROM users WHERE active = 1');

// Execute statement
$db->exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$userId]);

// Get last insert ID
$userId = $db->exec('INSERT INTO users (name) VALUES (?)', [$name]);
$newId = $db->lastInsertId();
```

### Transactions

```php
$result = db()->transaction(function($db) use ($userId, $amount) {
    // Deduct from user account
    $db->exec('UPDATE accounts SET balance = balance - ? WHERE user_id = ?',
        [$amount, $userId]);

    // Create transaction record
    $db->exec('INSERT INTO transactions (user_id, amount, type) VALUES (?, ?, ?)',
        [$userId, $amount, 'withdrawal']);

    return true;
});
```

### Tables (Query Builder & Repository Pattern)

Mini provides a fluent query builder with repository pattern support in `src/Tables/`:

```php
// Using the table() query builder
table('users')
    ->eq('status', 'active')
    ->gte('created_at', $date)
    ->order('name')
    ->limit(10)
    ->all();

// Get one record
$user = table('users')->eq('id', 123)->first();

// Count records
$count = table('posts')->eq('published', true)->count();

// Pagination
$page = table('users')->page(2, 20);  // Page 2, 20 per page
```

**Repository Pattern:**
- `DatabaseRepository` - Full CRUD with automatic SQL generation
- `ReadonlyRepositoryInterface` - Read-only data access
- `CsvRepository` - CSV file data sources
- `ScalarRepository` - Single-value repositories
- Type-safe hydration via `ObjectHydrationTrait`

See `src/Tables/README.md` for advanced usage.

## Migrations

### Running Migrations

```bash
composer exec mini migrations
```

### Creating Migrations

Create files in `_migrations/` directory with sequential naming:

```php
<?php
// _migrations/001_create_users_table.php

return function($db) {
    $db->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    echo "Created users table\n";
};
```

### Seeding Data

```php
<?php
// _migrations/002_seed_initial_users.php

return function($db) {
    $users = [
        ['admin', 'admin@example.com', password_hash('admin', PASSWORD_DEFAULT)],
        ['user', 'user@example.com', password_hash('user', PASSWORD_DEFAULT)]
    ];

    foreach ($users as [$username, $email, $hash]) {
        $db->exec(
            "INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)",
            [$username, $email, $hash]
        );
    }

    echo "Seeded " . count($users) . " users\n";
};
```

## Internationalization (i18n)

### Translation Files

Structure:

```
_translations/
├── default/              # Auto-generated source strings
│   └── controller.php.json
├── nb/                   # Norwegian
│   └── controller.php.json
└── es/                   # Spanish
    └── controller.php.json
```

### Basic Translation

**In your route handler:**

```php
// _routes/welcome.php

$username = $_SESSION['username'] ?? 'Guest';
echo t("Hello, {name}!", ['name' => $username]);
```

**Translation file** `_translations/nb/welcome.php.json`:

```json
{
  "Hello, {name}!": "Hei, {name}!"
}
```

**How it works:**
1. `t()` creates a `Translatable` object with source text and variables
2. Framework looks up translation in `_translations/{language}/{source-file}.json`
3. If found, uses translated text; otherwise falls back to source text
4. Variables are interpolated into the final string

**Auto-generating translation files:**

```bash
# Scans codebase for t() calls and creates translation files
composer exec mini translations add-missing

# Creates: _translations/default/welcome.php.json with source strings
# Creates: _translations/nb/welcome.php.json (empty, ready for translation)
```

### ICU MessageFormat (Plurals, Ordinals)

```php
// Plurals
echo t("{count, plural, =0{no messages} =1{one message} other{# messages}}",
    ['count' => $messageCount]);

// Ordinals
echo t("You finished {place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}!",
    ['place' => 21]);
// Output: "You finished 21st!"

// Gender/Select
echo t("{gender, select, male{He} female{She} other{They}} replied",
    ['gender' => $user->gender]);
```

### Formatting Before Translation

```php
// Format values in PHP before passing to t()
echo t("Price: {amount}", [
    'amount' => fmt()->currency(19.99, 'USD')
]);

echo t("Size: {size}", [
    'size' => fmt()->fileSize($bytes)
]);
```

### Translation Management CLI

```bash
composer exec mini translations                    # Validate translations
composer exec mini translations add-missing        # Add missing strings
composer exec mini translations add-language nb    # Create Norwegian translations
composer exec mini translations remove-orphans     # Clean up unused translations
```

### Complete Translation Workflow Example

**1. Write your code with t() calls:**

```php
// _routes/products/list.php

$products = db()->query('SELECT * FROM products')->fetchAll();

echo render('products/list.php', [
    'title' => t('Product Catalog'),
    'products' => $products,
    'empty_message' => t('No products found.')
]);
```

**2. Auto-generate translation files:**

```bash
composer exec mini translations add-missing
```

Creates:
- `_translations/default/products/list.php.json` (source strings)
- `_translations/nb/products/list.php.json` (empty, ready for translation)

**3. Translate strings:**

Edit `_translations/nb/products/list.php.json`:

```json
{
  "Product Catalog": "Produktkatalog",
  "No products found.": "Ingen produkter funnet."
}
```

**4. Add more languages:**

```bash
composer exec mini translations add-language es
```

Creates: `_translations/es/products/list.php.json`

Edit and translate:

```json
{
  "Product Catalog": "Catálogo de Productos",
  "No products found.": "No se encontraron productos."
}
```

**5. Users see content in their language:**

```php
// _config/bootstrap.php configured with LOK cookie
// User with LOK=nb_NO sees: "Produktkatalog"
// User with LOK=es_ES sees: "Catálogo de Productos"
// User with LOK=en_US sees: "Product Catalog" (original)
```

## Locale & Formatting

### Configuring Locale from Cookies

Here's a practical example using cookies to store user preferences:

```php
// _config/bootstrap.php

// Read user's locale and timezone from cookies
$locale = $_COOKIE['LOK'] ?? 'en_US';           // e.g., 'nb_NO'
$timezone = $_COOKIE['TZ'] ?? 'UTC';             // e.g., 'Europe/Oslo'

// Set locale for formatting (affects Fmt methods, number/date formatters)
\Locale::setDefault($locale);

// Set timezone for date/time operations
date_default_timezone_set($timezone);

// Extract language code from locale for translations (nb from nb_NO)
$language = \Locale::getPrimaryLanguage($locale);  // 'nb' from 'nb_NO'
translator()->trySetLanguageCode($language);

// Now all formatting and translations use the user's preferences:
// - Fmt::currency() formats according to $locale
// - Fmt::dateShort() formats according to $locale
// - t() translates to $language
// - new DateTime() uses $timezone
```

**Setting the cookies** (from a user settings page):

```php
// _routes/settings/save.php

$locale = $_POST['locale'] ?? 'en_US';      // e.g., 'nb_NO', 'en_US', 'de_DE'
$timezone = $_POST['timezone'] ?? 'UTC';    // e.g., 'Europe/Oslo', 'America/New_York'

// Validate timezone
if (!in_array($timezone, \DateTimeZone::listIdentifiers())) {
    $timezone = 'UTC';
}

// Set cookies (1 year expiration)
setcookie('LOK', $locale, time() + 31536000, '/');
setcookie('TZ', $timezone, time() + 31536000, '/');

// Redirect to apply new settings
redirect(url('settings'));
```

**Priority order** for detecting locale:

```php
// _config/bootstrap.php

// Priority: URL param > Cookie > Browser > Default
$locale = $_GET['lang']                          // ?lang=nb_NO (highest priority)
          ?? $_COOKIE['LOK']                      // LOK cookie
          ?? \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')  // Browser
          ?? 'en_US';                            // Default fallback

\Locale::setDefault($locale);

$language = \Locale::getPrimaryLanguage($locale);
translator()->trySetLanguageCode($language);
```

**Complete Settings Page Example:**

```php
// _routes/settings.php

$currentLocale = $_COOKIE['LOK'] ?? 'en_US';
$currentTimezone = $_COOKIE['TZ'] ?? 'UTC';

echo render('settings.php', [
    'title' => t('Settings'),
    'locale' => $currentLocale,
    'timezone' => $currentTimezone,
    'locales' => [
        'en_US' => t('English (United States)'),
        'en_GB' => t('English (United Kingdom)'),
        'nb_NO' => t('Norwegian (Norway)'),
        'de_DE' => t('German (Germany)'),
        'es_ES' => t('Spanish (Spain)'),
        'fr_FR' => t('French (France)')
    ],
    'timezones' => [
        'UTC' => 'UTC',
        'Europe/Oslo' => 'Europe/Oslo',
        'Europe/London' => 'Europe/London',
        'America/New_York' => 'America/New_York',
        'America/Los_Angeles' => 'America/Los_Angeles',
        'Asia/Tokyo' => 'Asia/Tokyo'
    ]
]);
```

```php
// _views/settings.php
<?php $content = ob_start(); ?>

<h1><?= h($title) ?></h1>

<form method="POST" action="<?= url('settings/save') ?>">
    <label>
        <?= t('Language & Region') ?>
        <select name="locale">
            <?php foreach ($locales as $code => $name): ?>
                <option value="<?= h($code) ?>" <?= $code === $locale ? 'selected' : '' ?>>
                    <?= h($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <?= t('Timezone') ?>
        <select name="timezone">
            <?php foreach ($timezones as $tz => $label): ?>
                <option value="<?= h($tz) ?>" <?= $tz === $timezone ? 'selected' : '' ?>>
                    <?= h($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <button type="submit"><?= t('Save Settings') ?></button>
</form>

<?php
$content = ob_get_clean();
echo render('layout.php', compact('title', 'content'));
?>
```

### Formatting

```php
use mini\I18n\Fmt;

// All methods use \Locale::getDefault()

// Currency
echo Fmt::currency(19.99, 'USD');     // $19.99 (en_US) or USD 19,99 (nb_NO)

// Dates
echo Fmt::dateShort(new DateTime());               // 10/28/2025
echo Fmt::dateLong(new DateTime());                // October 28, 2025
echo Fmt::timeShort(new DateTime());               // 2:30 PM
echo Fmt::dateTimeShort(new DateTime());           // 10/28/2025, 2:30 PM

// Numbers
echo Fmt::number(1234.56, 2);         // 1,234.56 (en_US) or 1 234,56 (nb_NO)
echo Fmt::percent(0.85, 1);           // 85.0% (en_US) or 85,0 % (nb_NO)

// File sizes
echo Fmt::fileSize(1048576);          // 1.0 MB
```

## Caching

### Basic Caching

```php
$cache = cache();  // Root cache

// Set with TTL (in seconds)
$cache->set('user:123', $userData, 3600);

// Get value
$data = $cache->get('user:123');

// Get with default
$data = $cache->get('user:123', ['default' => 'value']);

// Delete
$cache->delete('user:123');

// Has
if ($cache->has('user:123')) {
    // ...
}
```

### Namespaced Caching

```php
$userCache = cache('users');
$postCache = cache('posts');

$userCache->set('user:123', $userData, 3600);
$postCache->set('post:456', $postData, 3600);

// Isolated namespaces
$userCache->get('user:123');  // Returns userData
$postCache->get('user:123');  // Returns null (different namespace)
```

## Logging

### Basic Logging

```php
// Get logger instance (PSR-3 compatible) and log directly
log()->debug('Debug message');
log()->info('Info message');
log()->notice('Notice message');
log()->warning('Warning message');
log()->error('Error message');
log()->critical('Critical message');
log()->alert('Alert message');
log()->emergency('Emergency message');
```

### Context & Interpolation

```php
// With context
log()->info('User {user} logged in from {ip}', [
    'user' => $username,
    'ip' => $_SERVER['REMOTE_ADDR']
]);

// With exception
try {
    // ... code
} catch (\Exception $e) {
    log()->error('Operation failed: {message}', [
        'message' => $e->getMessage(),
        'exception' => $e
    ]);
}
```

## Templates

Templates are stored in `_views/` directory and resolved using the path registry.

### Simple Templates

```php
// _routes/users.php
$users = db()->query('SELECT * FROM users ORDER BY name')->fetchAll();

echo render('users.php', [
    'title' => t('User List'),
    'users' => $users
]);
```

```php
<?php // _views/users.php ?>
<h1><?= h($title) ?></h1>

<ul>
    <?php foreach ($users as $user): ?>
        <li>
            <a href="<?= url("users/{$user['id']}") ?>">
                <?= h($user['name']) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
```

### Template Inheritance

Mini supports Twig-like template inheritance using pure PHP. Child templates extend parent layouts and define named blocks.

**Parent Layout (_views/layout.php):**
```php
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php $show('title', 'My Site'); ?></title>
</head>
<body>
  <header>
    <h1><?php $show('header', 'Welcome'); ?></h1>
  </header>

  <main>
    <?php $show('content'); ?>
  </main>

  <footer>
    <?php $show('footer', '© ' . date('Y')); ?>
  </footer>
</body>
</html>
```

**Child Template (_views/users.php):**
```php
<?php
// Extend parent layout
$extend('layout.php');

// Define title block (inline syntax)
$block('title', 'User List');

// Define content block (buffered syntax)
$block('content'); ?>
  <h2>All Users</h2>
  <ul>
    <?php foreach ($users as $user): ?>
      <li><?= h($user['name']) ?></li>
    <?php endforeach; ?>
  </ul>
<?php $end();
```

**Template Helpers:**
- `$extend('file.php')` - Extend parent layout
- `$block('name', ?string $value = null)` - Define block (dual-use: inline or buffered)
- `$end()` - End buffered block capture
- `$show('name', 'default')` - Output block with optional default

**Dual-Use `$block()` Syntax:**
```php
// Inline: set block to value directly
<?php $block('title', 'My Page'); ?>

// Buffered: capture complex content
<?php $block('content'); ?>
  <p>Complex HTML here</p>
<?php $end(); ?>

// Including sub-templates (partials)
<?= mini\render('_user-card.php', ['user' => $currentUser]) ?>
```

**Benefits:**
- Pure PHP, opcache-friendly
- No compilation step needed
- Blocks with defaults
- Reusable partials
- Clean separation of layout and content

### Layout File

```php
<?php // Example: More complex layout ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= h($title) ?></title>
</head>
<body>
    <?= $content ?>
</body>
</html>
```

**Subdirectories:** You can organize templates: `render('admin/dashboard.php')`

## Testing

Tests go in `tests/` directory. Mini uses a simple test helper pattern:

```php
<?php
// tests/MyFeature.php

require_once __DIR__ . '/../vendor/autoload.php';

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
        throw new \Exception($message ?: "Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true));
    }
}

// Tests
test('Basic functionality works', function() {
    $result = 2 + 2;
    assertEqual(4, $result);
});

test('Database query returns results', function() {
    mini\bootstrap();
    $users = db()->query('SELECT * FROM users')->fetchAll();
    assertEqual(true, is_array($users));
});
```

Run tests:

```bash
php tests/MyFeature.php
```

## Authentication

Mini provides auth helpers, but you implement the authentication logic:

### Setting Up Auth

```php
// _config/bootstrap.php

use mini\Auth\AuthInterface;

class MyAuth implements AuthInterface {
    public function isAuthenticated(): bool {
        session_start();
        return isset($_SESSION['user_id']);
    }

    public function getUserId(): mixed {
        return $_SESSION['user_id'] ?? null;
    }

    public function hasRole(string $role): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $userRole = db()->queryField(
            'SELECT role FROM users WHERE id = ?',
            [$this->getUserId()]
        );

        return $userRole === $role;
    }
}

// Register auth implementation
setupAuth(fn() => new MyAuth());
```

### Using Auth

```php
// _routes/admin/dashboard.php

require_login();                  // Throws 401 if not logged in
require_role('admin');            // Throws 403 if not admin

echo render('admin/dashboard.php', [
    'title' => t('Admin Dashboard')
]);
```

## Core Functions Reference

```php
// Translation
t(string $text, array $vars = []): Translatable

// HTML escaping
h(string $str): string

// Template rendering
render(string $template, array $vars = []): string

// URL generation
url(string $path = '', array $query = []): string

// Redirects
redirect(string $url, int $statusCode = 302): void

// Current URL
current_url(): string

// Flash messages
flash_set(string $type, string $message): void
flash_get(): array

// Session
session(): bool

// Database
db(): DatabaseInterface

// Cache
cache(?string $namespace = null): CacheInterface

// Logger
log(): LoggerInterface

// Tables (query builder)
table(string $name): Repository

// Translator (language management)
translator(): Translator

// Formatting
fmt(): Fmt

// Auth
auth(): ?AuthInterface
is_logged_in(): bool
require_login(): void
require_role(string $role): void

// Framework
bootstrap(): void
router(): void
```

## Philosophy: Why Native PHP?

Other frameworks wrap PHP in layers of abstraction. We don't.

### `$_POST` and `$_GET` Are Not Dangerous

```php
// Mini - direct and clear
$username = $_POST['username'] ?? '';
$email = $_POST['email'] ?? '';

// Framework wrappers add overhead for no real benefit
$username = $request->input('username', '');  // Laravel
$email = $request->getParsedBody()['email'] ?? '';  // PSR-7
```

**Our view:** `$_POST` and `$_GET` are request-scoped. PHP manages them correctly. Wrapping them in objects adds indirection without solving any actual problem.

### Locale Management Is Built-In

```php
// Mini - use PHP's native locale handling
\Locale::setDefault('nb_NO');
$formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::CURRENCY);

// We don't wrap what PHP does well
```

### Direct SQL When You Need It

```php
// Mini - direct SQL is fine
$users = db()->query('SELECT * FROM users WHERE active = 1')->fetchAll();

// Query builder when convenient
$users = table('users')->eq('active', 1)->all();

// We give you both - use what fits
```

## Performance

File-based routing and lazy initialization mean Mini stays fast as your app grows:

- **No route compilation** - Routes are discovered on-demand
- **No container compilation** - Services initialize when used
- **No middleware overhead** - Direct execution path
- **Linear scaling** - 10 routes or 10,000 routes, same performance

## Extending Mini

### Use Any PSR Package

```bash
composer require symfony/mailer
composer require league/flysystem
composer require respect/validation
```

Mini is PSR-compatible, so Symfony, League, and other packages work directly.

### Override Framework Services

See `PATTERNS.md` for detailed examples of overriding framework defaults (logger, cache, database, etc.).

## When Mini Isn't Right

**Choose another framework if:**
- You need queues, events, notifications built-in (Laravel)
- You require PSR-7/15 middleware architecture (Slim)
- Your team needs heavy conventions and structure (Laravel, Symfony)
- You prefer heavy abstractions over direct PHP

**Mini is for developers who:**
- Value directness and transparency
- Want to use PHP idiomatically
- Prioritize performance and simplicity
- Need complete features without complexity

## License

MIT - Build whatever you want, wherever you want.
