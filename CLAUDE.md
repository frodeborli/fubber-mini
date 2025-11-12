# Mini Framework - Overview for Claude Code

## Development Status

**Mini is in active internal development.** We prioritize clean, simple code over backward compatibility:

- When we find a better approach, we **remove the old implementation** entirely
- We don't maintain redundant code or deprecated patterns
- Breaking changes are documented in **CHANGE-LOG.md** for reference
- No semantic versioning or release process - it's internal iteration

**See CHANGE-LOG.md** when reviewing old code or conversation history.

## Dependency Policy

**Mini is a zero-dependency monolith framework** to enable safe sub-application mounting:

- **Zero required dependencies** - No external packages in `composer.json` "require" section (only PHP itself)
- **No PSR implementation dependencies** - Don't require `nyholm/psr7`, `guzzlehttp/psr7`, etc.
- **No polyfills** - Throw `MissingDependencyException` instead of depending on `symfony/polyfill-*`
- **Optional features via suggestions** - Use `composer.json` "suggest" for optional integrations
  - Example: `symfony/mailer` → suggested, `mini\mail()` throws if not installed
- **Wide version support** - When we DO depend on PSR interfaces (dev/suggest), support multiple major versions
  - Example: `"psr/http-message": "^1.0 || ^2.0 || ^3.0"`

**Why?** This allows mounting sub-applications with their own dependencies without conflicts:
```php
_routes/marketing/__DEFAULT__.php  // Slim 4 app (guzzle 7.x)
_routes/support/__DEFAULT__.php    // Symfony app (guzzle 6.x)
_routes/api/__DEFAULT__.php        // Mini native (no conflicts!)
```

## Philosophy: Back to PHP Basics

Mini is a deliberate departure from modern PHP frameworks:

- **No PSR-7 abstractions** - Use `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION`, `echo`, `header()` directly
- **Native PHP over wrappers** - Call `\Locale::setDefault()` and `date_default_timezone_set()` directly
- **Convenience functions** - `db()`, `fmt()`, `t()`, `render()` for common tasks
- **Self-documenting** - Documentation lives in docblocks and `docs/` folder
- **Convention over configuration** - Sensible defaults, minimal setup

## Documentation Tool for Code Exploration

**IMPORTANT: Use the `mini docs` CLI tool to explore this codebase efficiently.**

```bash
vendor/bin/mini docs --help    # See all available commands
```

This tool provides **reflection-based queries** that are far more powerful than text search:
- Find implementations: `mini docs implements CacheInterface`
- Find subclasses: `mini docs extends Repository`
- Type-based search: `mini docs accepts DatabaseInterface`
- Signature search: `mini docs search '$action'`
- And much more...

**Always run `vendor/bin/mini docs --help` to see current capabilities.** The tool is actively developed and may have features not documented here.

## Finding Documentation

**Mini documents itself through its codebase:**

1. **`mini docs` tool** - Your primary exploration tool (see above)
2. **Source files** - Read class/function docblocks directly (e.g., `src/Mini.php`, `src/SimpleRouter.php`)
3. **docs/ folder** - Standalone markdown files for concepts and patterns
4. **WRITING-DOCUMENTATION.md** - Documentation conventions and structure

**Don't look in CLAUDE.md for implementation details - use the docs tool or read source files directly.**

## Quick Start

### Basic Routing

```php
// html/index.php (entry point)
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();
```

```php
// _routes/users.php (handles /users)
<?php
header('Content-Type: application/json');
echo json_encode(['users' => db()->query("SELECT * FROM users")->fetchAll()]);
```

File-based routing:
- `/` → `_routes/index.php`
- `/users` → `_routes/users.php`
- `/api/posts` → `_routes/api/posts.php`

Pattern-based routing:
```php
// _routes/blog/__DEFAULT__.php
return [
    '/' => 'index.php',
    '/{slug}' => fn($slug) => "post.php?slug=$slug",
];
```

### Using Native PHP

```php
// Reading input
$id = $_GET['id'];
$email = $_POST['email'];
$token = $_COOKIE['token'];
$cart = $_SESSION['cart'];

// Sending output
header('Content-Type: application/json');
http_response_code(201);
echo json_encode(['success' => true]);
```

### Database

```php
// Query
$users = db()->query("SELECT * FROM users WHERE active = ?", [1])->fetchAll();

// Insert
db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);

// Update
db()->update('users', ['active' => 0], 'id = ?', [123]);

// Delete
db()->delete('users', 'id = ?', [123]);
```

### Internationalization

```php
// Set locale per-request (use PHP's native functions)
\Locale::setDefault('de_DE');
date_default_timezone_set('Europe/Berlin');

// Translation
echo t("Hello {name}", ['name' => 'World']);

// ICU MessageFormat for plurals
echo t("{count, plural, =0{no items} one{# item} other{# items}}", ['count' => 3]);

// Formatting (uses current locale automatically)
echo Fmt::currency(19.99, 'EUR');  // "19,99 €"
echo Fmt::dateShort(new DateTime());  // "29.10.2025"
```

### Simple "Middleware"

```php
// helpers.php (autoloaded via composer.json)
function requireAuth(): void {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// _routes/admin/users.php
<?php
requireAuth();  // Just call it
// ... rest of route
```

## Directory Structure

```
project/
├── _routes/           # Route handlers (not web-accessible)
├── _config/           # Configuration files
├── _errors/           # Error page templates (401.php, 404.php, etc.)
├── _translations/     # Translation files
├── html/ or public/   # Document root (web-accessible)
│   ├── index.php     # Entry point: calls mini\router()
│   └── assets/       # Static files
└── vendor/           # Composer dependencies
```

**Security**: Directories starting with `_` are not web-accessible.

## Core Singleton

```php
Mini::$mini->root       // Project root
Mini::$mini->docRoot    // Web-accessible directory
Mini::$mini->baseUrl    // Application base URL
Mini::$mini->debug      // Debug mode
Mini::$mini->locale     // Default locale
Mini::$mini->timezone   // Default timezone
```

Configure via environment variables: `MINI_ROOT`, `MINI_DOC_ROOT`, `MINI_BASE_URL`, `DEBUG`, `MINI_LOCALE`, `MINI_TIMEZONE`.

**For detection logic and priority, see `src/Mini.php` docblock.**

## Common Tasks

### Sessions
```php
session();  // Starts session if not already started
$_SESSION['user_id'] = 123;
```

### Rendering Templates
```php
echo render('template-name', ['var' => 'value']);
```

### Phase Lifecycle Hooks
```php
// Subscribe to phase transitions
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Fires when entering Ready phase (before transition completes)
});

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Fires after Ready phase entered (after transition completes)
});
```

**For detailed hooks documentation, see `src/Phase.php` and `src/Hooks/` classes.**

## Learning More

- **Source code**: Read class docblocks in `src/` (e.g., `src/Mini.php`, `src/SimpleRouter.php`)
- **Patterns**: See `docs/` folder for detailed guides
- **Examples**: Look at test files in `tests/` for usage examples

Mini embraces simplicity: if you know PHP, you know Mini.
