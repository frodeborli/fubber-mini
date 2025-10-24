# Mini Framework

A deliberately minimal PHP micro-framework for experienced developers who want powerful features without architectural complexity.

## Philosophy

**Get out of the way.** Mini provides enterprise-grade i18n, caching, database abstraction, and formatting—then disappears. No dependency injection containers, no service discovery, no magic. Just the tools you need to build applications quickly and reliably.

**Fault isolation over global coupling.** Each endpoint is an independent PHP file. If `/api/analytics.php` has a bug, the rest of your application keeps running. This isn't just convenient—it's operational resilience.

**Convention over configuration.** Sensible defaults, minimal setup, maximum productivity.

## Core Functions Reference

Mini provides a focused set of core functions designed for long-term stability. These functions form the public API and won't be removed or significantly changed:

**Essential Functions:**
- `mini\bootstrap()` - Initialize the framework
- `mini\t()` - Translate text with variable interpolation
- `mini\h()` - HTML escape for XSS protection
- `mini\render()` - Render templates with variable extraction
- `mini\url()` - Generate URLs with base_url handling

**Data Access:**
- `mini\db()` - Database singleton for queries
- `mini\table()` - Repository access for typed queries
- `mini\cache()` - PSR-16 cache with namespacing

**Formatting:**
- `mini\fmt()` - Locale-aware formatting (dates, currency, filesize)
- `mini\collator()` - String collation for sorting

**Authentication:**
- `mini\is_logged_in()` - Check authentication status
- `mini\require_login()` - Enforce login requirement
- `mini\require_role()` - Enforce role-based access
- `mini\auth()` - Access authentication system

**Session:**
- `mini\session()` - Safe session initialization

**Routing:**
- `mini\router()` - Handle dynamic routing (called by router.php)

## Core Features

### Internationalization (i18n)

Enterprise-grade translation system with both **standard ICU MessageFormat** and **advanced conditional logic** for business rules:

```php
// Basic usage
echo t("Hello, {name}!", ['name' => $username]);

// ICU MessageFormat (RECOMMENDED for pluralization/ordinals)
echo t("You have {count, plural, =0{no messages} =1{one message} other{# messages}}", ['count' => $messageCount]);
echo t("You finished {place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}!", ['place' => 21]);

// Custom filters for domain-specific formatting
translator()->getInterpolator()->addFilterHandler(function($value, $filter) {
    if ($filter === 'currency') return '$' . number_format($value, 2);
    return null;
});
echo t("Price: {amount|currency}", ['amount' => 199.99]);
```

**Standard i18n Features (use ICU MessageFormat):**
- **Pluralization** (`{count, plural, one{#} other{#}}`)
- **Ordinals** (`{rank, selectordinal, one{#st} other{#th}}`)
- **Select formats** (`{gender, select, male{he} female{she} other{they}}`)
- **Number/date formatting** with locale-aware rules
- **Full Unicode CLDR compliance** for all languages

**Advanced Conditional Logic (for business rules):**
- **Multi-variable conditions** (`count=1&priority=high`)
- **Range queries** (`score:gte=90`, `total:lt=50`)
- **Complex business logic** in translation files (not code)
- **A/B testing** and feature flag support in messages
- **Configuration-driven** messaging for non-technical teams

**Core Translation Features:**
- **Fallback chains** (target → regional → default → source text)
- **Auto-generation** of translation files from source code
- **Professional CLI tool** for translation management
- **Variable interpolation** with custom filters
- **Context extraction** for translators

**Translation Management CLI:**
```bash
composer exec mini translations                    # Validate translations
composer exec mini translations add-missing        # Add missing strings
composer exec mini translations add-language es    # Create Spanish translations
composer exec mini translations remove-orphans     # Clean up unused translations
```

### Database

Simple, powerful database abstraction:

```php
$db = db();  // Lazy-initialized singleton

// Queries
$user = $db->queryOne('SELECT * FROM users WHERE id = ?', [$userId]);
$users = $db->query('SELECT * FROM users WHERE active = 1');
$count = $db->queryField('SELECT COUNT(*) FROM users');

// Updates
$db->exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$userId]);
$userId = $db->exec('INSERT INTO users (name) VALUES (?)', [$name]);
```

### Localized Formatting

Timezone-aware, locale-specific formatting:

```php
use function mini\fmt;

// Formatting methods use current locale automatically
echo fmt()->dateShort(new DateTime());                    // Uses Locale::getDefault()
echo fmt()->dateTimeShort(new DateTime('2024-01-15 10:30:00')); // DateTime objects
echo fmt()->timeShort(new DateTime());                    // Time formatting

// Timezone handled via DateTimeZone
$dateInTimezone = new DateTime('now', new DateTimeZone('Europe/Oslo'));
echo fmt()->dateShort($dateInTimezone);

// Formatting with explicit parameters for safety
echo fmt()->currency(199.99, 'USD');  // MUST specify currency code
echo fmt()->percent(0.85, 1);         // Decimal places optional
echo fmt()->fileSize(1048576);        // File sizes
```

### Caching

Flexible caching with multiple backends:

```php
$cache = cache();            // Default cache
$userCache = cache('users'); // Namespaced cache

$cache->set('key', $data, 3600);  // Set with TTL
$data = $cache->get('key');       // Get value
$cache->delete('key');            // Remove
$cache->clear();                  // Clear namespace
```

## Routing: File-Based with Optional Enhancement

**Pragmatic URL Management**

Mini's routing follows the same philosophy as everything else - simple by default, powerful when needed.

### Basic File-Based Routing

File-based routing behavior depends on whether `router.php` exists in your web root:

| File Path | Without router.php | With router.php |
|-----------|-------------------|-----------------|
| `/api/ping.php` | `/api/ping.php` | `/api/ping` (clean URL) |
| `/api/ping/index.php` | `/api/ping/index.php` | `/api/ping/` (clean URL) |
| `/users.php?id=123` | `/users.php?id=123` | `/users?id=123` (no .php) |

**Without router.php:**
- Direct file access with `.php` extension visible
- Simple, works immediately
- No configuration needed

**With router.php:**
- Clean URLs without `.php` extensions
- Automatic 301 redirects from old-style URLs
- Supports custom route patterns via `config/routes.php`
- Subfolder routing via `_routes.php` files

### Automatic Clean URL Redirects

When `/router.php` exists in your application root, `mini\bootstrap()` automatically handles clean URL redirects:

**PHP Extension Hiding:**
- Browser requests `/users.php?id=123` → 301 redirect to `/users?id=123`
- Browser requests `/api/ping.php` → 301 redirect to `/api/ping`

**Index File Handling:**
- Browser requests `/users/index.php` → 301 redirect to `/users/`
- Router then internally includes `/users/index.php` for `/users/` requests

**How it works:**
1. User visits `/users.php?id=123` (old-style URL with visible PHP extension)
2. `mini\bootstrap()` detects the `.php` extension
3. Issues 301 redirect to `/users?id=123` (clean URL)
4. `/router.php` handles the clean URL and internally includes the appropriate file

**Internal routing process:**
1. Browser requests `/users/123` (clean URL)
2. Router matches pattern and determines target file
3. Sets `$_GET['id'] = "123"`
4. Internally includes `/users.php` (no redirect to user's browser)
5. `/users.php` executes with the populated `$_GET` array

This ensures:
- **SEO-friendly URLs** - no `.php` extensions visible
- **Backward compatibility** - old URLs still work via redirects
- **Automatic canonicalization** - all URLs are consistently clean

### Enhanced Routing for Collections

When you need pretty URLs for collections, create `config/routes.php`:

```php
<?php
return [
    "/users/{id:\d+}" => fn($id) => "/api/users.php?id={$id}",
    "/articles/{slug}" => function(string $slug) {
        // Find article ID from cache/database
        $articleId = cache()->get("article_slug:{$slug}")
                   ?? db()->queryField('SELECT id FROM articles WHERE slug = ?', [$slug]);

        if (!$articleId) {
            http_response_code(404);
            return "/404.php";
        }

        return "/article.php?id={$articleId}";
    }
];
```

**This enables:**
- `/articles/my-great-post` → internally includes `article.php` with `$_GET['id'] = "12345"`
- `/users/123` → internally includes `api/users.php` with `$_GET['id'] = "123"`
- Database lookups for slug-to-ID mapping
- Custom 404 handling per route

### Why This Approach Works

**File-based foundation:**
```
/api/users.php               # Direct endpoint
/article.php                 # Article display
/404.php                     # Error handling
```

**Router enhancement:**
- **Optional** - only needed for pretty URLs
- **Simple mapping** - routes to existing files
- **No controllers** - routes point to the actual PHP files
- **Custom logic** - closures can handle complex routing needs

**Advantages:**
- **No route definitions for simple cases** - filesystem IS the routing table
- **Fault isolation** - broken endpoint doesn't crash the app
- **Direct deployment** - add file, endpoint exists
- **Enhanced when needed** - add routing only for collections/pretty URLs
- **Performance** - minimal overhead, direct file execution

### Subfolder Routing

For complex applications, you can create `_routes.php` files in subfolders to handle routing for that directory:

```
/api/
├── users.php
├── _routes.php        # Routes specific to /api/*
└── admin/
    ├── dashboard.php
    └── _routes.php    # Routes specific to /api/admin/*
```

Each `_routes.php` file works the same as `config/routes.php` but is scoped to its directory.

### Special Controller Files

Mini recognizes certain filenames as having special behavior:

| Filename | Purpose | When Used |
|----------|---------|-----------|
| `router.php` | Enable clean URLs and custom routing | Must be in web root |
| `404.php` | Handle not found errors | Called when route/file not found |
| `403.php` | Handle access denied errors | Called on `AccessDeniedException` |
| `500.php` | Handle server errors | Called on unhandled exceptions |
| `_routes.php` | Subfolder-specific routing config | Can exist in any directory |

**Note:** These special files use privileged names. If you need routes like `/404` or `/router`, consider naming them `_404.php`, `_router.php` to avoid conflicts.

### URL Generation: Explicit Over Magic

Mini does **not** provide reverse routing or named routes. Instead, you hardcode URLs using the `url()` helper:

```php
// In templates and endpoints
echo url('api/users');                    // /api/users
echo url('articles/my-great-post');       // /articles/my-great-post
echo url("users/{$userId}");              // /users/123

// In forms and links
<form action="<?= url('api/login') ?>">
<a href="<?= url("articles/{$article['slug']}") ?>">Read More</a>
```

**Why no reverse routing?**

1. **URL structure rarely changes** - we've almost never encountered the desire to significantly restructure URLs in production applications

2. **External constraints remain** - even if you change internal routing, external inbound links, bookmarks, and SEO won't change. You're bound by previous URL choices regardless.

3. **Explicit cost for rare changes** - when URL structure does change, you'll need to:
   - Update hardcoded URLs (find/replace across codebase)
   - Create redirects from old endpoints to maintain external links
   - This explicit cost reflects the real impact of URL changes

4. **Simplicity over abstraction** - no route names to remember, no reverse routing configuration, just direct URL construction

**The `url()` function:**
- Handles base URL configuration
- Ensures consistent URL generation
- Works with both file-based and enhanced routing
- Simple string concatenation - no magic

**Custom URL generation encouraged:**

You're absolutely encouraged to implement your own URL generation methods:

```php
class User {
    public function getUrl(): string {
        return url("users/{$this->id}");
    }

    public function getEditUrl(): string {
        return url("users/{$this->id}/edit");
    }
}

class Article {
    public function getUrl(): string {
        return url("articles/{$this->slug}");
    }
}

// Usage
echo $user->getUrl();        // /users/123
echo $article->getUrl();     // /articles/my-great-post
```

**The difference:** We won't provide a central facility that you need to learn to configure. Instead, implement URL generation wherever it makes sense for your domain models and use cases.

## Quick Start

### Installation

```bash
composer require fubber/mini
```

### Basic Application Structure

```
your-app/
├── config.php                  # App configuration (required)
├── config/
│   ├── bootstrap.php           # Application-specific setup (optional)
│   └── formats/
│       ├── en.php              # English formatting
│       └── nb_NO.php           # Norwegian formatting
├── translations/
│   ├── default/                # Auto-generated source strings
│   └── nb_NO/                  # Norwegian translations
├── migrations/                 # Database migrations
├── api/
│   ├── ping.php               # GET /api/ping
│   └── users/
│       ├── index.php          # GET/POST /api/users/
│       └── [id].php           # GET /api/users/123
└── index.php                  # Main page
```

**Note:** All PHP files that use the framework should:
1. Load Composer's autoloader: `require_once __DIR__ . '/vendor/autoload.php';`
2. Call `mini\bootstrap()` to initialize the framework
3. Access config via `$GLOBALS['app']['config']`

### Configuration (config.php)

Create a `config.php` file in your project root:

```php
<?php
return [
    'base_url' => 'https://your-domain.com',
    'dbfile' => __DIR__ . '/database.sqlite3',
    'default_language' => 'en',
    'app' => [
        'name' => 'Your Application'
    ]
];
```

### Application Bootstrap (config/bootstrap.php)

The bootstrap file is automatically included by the Mini framework and is where you configure application-specific settings:

```php
<?php
// config/bootstrap.php

use function mini\{db, translator, fmt};

// Language detection with priority: URL param > user preference > browser > default
$languageCode = $_GET['lang'] ?? null;

// Get user language preference if logged in
if (!$languageCode && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    try {
        $languageCode = db()->queryField('SELECT language FROM users WHERE id = ?', [$_SESSION['user_id']]);
    } catch (\Exception $e) {
        // Language column might not exist yet - gracefully continue
    }
}

// Set language if we found one
if ($languageCode && translator()->trySetLanguageCode($languageCode)) {
    // Language is now handled automatically by Locale::setDefault() in mini\bootstrap()
}

// Set user timezone from preference
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    try {
        $userTimezone = db()->queryField('SELECT timezone FROM users WHERE id = ?', [$_SESSION['user_id']]);
        if ($userTimezone) {
            // Timezone handling is now via DateTimeZone or intlDateFormatter factory function
        }
    } catch (\Exception $e) {
        // Timezone column might not exist yet - use default
    }
}

// Add custom translation filters
translator()->getInterpolator()->addFilterHandler(function($value, $filter) {
    if ($filter === 'currency') return '$' . number_format($value, 2);
    if ($filter === 'filesize') return fmt()->fileSize($value);
    return null; // Let other handlers try
});
```

**Bootstrap features:**
- **Automatic inclusion** - loaded by Mini framework after core initialization
- **Language detection** - URL parameters, user preferences, browser detection
- **User-specific settings** - timezone and language from user profiles
- **Custom filters** - extend translation system with domain-specific formatting
- **Graceful degradation** - handles missing database columns during development

**What belongs in bootstrap:**
- Application-wide configuration that depends on user context
- Custom translation filters and formatters
- User preference detection and application
- Feature flags and environment-specific setup

### Basic Endpoint (api/ping.php)

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use function mini\{bootstrap, t};

bootstrap();

$config = $GLOBALS['app']['config'];

header('Content-Type: application/json');

echo json_encode([
    'message' => t('Pong from {app}!', ['app' => $config['app']['name']]),
    'timestamp' => (new DateTime())->format('c'), // ISO 8601 format
    'server_time' => (new DateTime('now', new DateTimeZone('UTC')))->format('H:i:s')
]);
```

## Template Rendering with `mini\render()`

The `render()` function provides simple, secure templating with variable extraction:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function mini\{bootstrap, render, t, db};

bootstrap();

$config = $GLOBALS['app']['config'];
$users = db()->query('SELECT * FROM users ORDER BY name');

echo render('templates/users.php', [
    'title' => t('User List'),
    'users' => $users,
    'config' => $config
]);
```

**Template file (templates/users.php):**
```php
<?php $content = ob_start(); ?>

<h1><?= h($title) ?></h1>

<ul>
    <?php foreach ($users as $user): ?>
        <li><?= h($user['name']) ?> - <?= h($user['email']) ?></li>
    <?php endforeach; ?>
</ul>

<?php $content = ob_get_clean(); ?>
<?= render('templates/layout.php', compact('title', 'config', 'content')) ?>
```

**Key features:**
- **Variable extraction** - array keys become variables
- **Nested rendering** - templates can render other templates
- **XSS protection** - always use `h()` for output escaping
- **No magic** - just PHP with helper functions

## Advanced Translation Features

### Conditional Translations with QueryParser

Mini provides a unique conditional translation system for business logic that goes beyond standard i18n:

#### **When to Use Conditional Translations**

**❌ Don't use for standard i18n (use ICU MessageFormat instead):**
```php
// BAD: Don't reinvent pluralization
"message": {
  "count=0": "No messages",
  "count=1": "One message",
  "": "{count} messages"
}

// GOOD: Use ICU MessageFormat
t("You have {count, plural, =0{no messages} =1{one message} other{# messages}}", ['count' => $count])
```

**✅ Do use for business logic and multi-variable conditions:**
```json
{
  "shipping_message": {
    "total:gte=50&country=US": "🚛 Free shipping to US!",
    "total:gte=100&country=CA": "🚛 Free shipping to Canada!",
    "weight:gt=20": "📦 Oversized shipping applies",
    "": "Shipping calculated at checkout"
  },
  "membership_status": {
    "points:gte=10000&tenure:gte=24": "💎 Diamond Member (Lifetime benefits!)",
    "points:gte=5000": "🥇 Gold Member",
    "points:gte=1000": "🥈 Silver Member",
    "": "Basic Member"
  }
}
```

#### **QueryParser Syntax**

**Operators:**
- `=` - Exact match (`status=pending`)
- `:gte=` - Greater than or equal (`score:gte=90`)
- `:gt=` - Greater than (`age:gt=18`)
- `:lte=` - Less than or equal (`total:lte=100`)
- `:lt=` - Less than (`usage:lt=80`)
- `&` - AND logic (`items:gte=3&member_level=gold`)

**Usage:**
```php
// In your code
echo t("shipping_message", [
    'total' => 75.50,
    'country' => 'US',
    'weight' => 15
]);
// Result: "🚛 Free shipping to US!"
```

#### **Transformations System**

For language-specific formatting rules beyond ICU:

**translations/default/transformations.json:**
```json
{
  "{grade}": {
    "grade:gte=97": "A+ (Outstanding!)",
    "grade:gte=93": "A (Excellent)",
    "grade:gte=90": "A- (Great)",
    "grade:gte=87": "B+ (Good)",
    "": "Grade: {grade}%"
  }
}
```

**Usage:**
```php
echo t("Your grade: {score:grade}", ['score' => 95]);
// Result: "Your grade: A (Excellent)"
```

**⚠️ Recommendation:** Use ICU MessageFormat for standard i18n, conditional translations for business logic only.

### ICU MessageFormat Integration

Mini automatically detects and processes ICU MessageFormat patterns:

```php
// ICU patterns are processed with PHP's MessageFormatter
echo t("Today is {date, date, full}", ['date' => new DateTime()]);
echo t("Price: {amount, number, currency}", ['amount' => 19.99]);
echo t("{count, plural, =0{No items} one{One item} other{# items}}", ['count' => 5]);
```

### Translation Resolution & Language Detection

Mini uses a sophisticated multi-step process to find the best translation:

1. **Language Detection Priority:**
   - URL parameter (`?lang=no`)
   - User preference (from database)
   - Browser `Accept-Language` header
   - Default language from config

2. **File Resolution with Fallback Chain:**
   ```
   translations/nb_NO/api/users.php.json    # Target language
   translations/no/api/users.php.json       # Regional fallback
   translations/default/api/users.php.json  # Source strings
   Source text itself                       # Final fallback
   ```

3. **Translation Selection within File:**
   - Exact string match
   - Conditional match using QueryParser
   - Fallback to default language
   - Return source text

### QueryParser: Conditional Translation Logic

The QueryParser enables complex translation rules using query-string syntax:

```json
{
  "You have {count} messages": {
    "count=0": "You have no messages",
    "count=1": "You have one message",
    "count:gte=2": "You have {count} messages"
  },
  "{ordinal}": {
    "ordinal:gte=10&ordinal:lte=13": "{ordinal}th",
    "ordinal:like=*1": "{ordinal}st",
    "ordinal:like=*2": "{ordinal}nd",
    "ordinal:like=*3": "{ordinal}rd",
    "": "{ordinal}th"
  }
}
```

**Supported operators:**
- `=` - Exact match
- `gt`, `gte`, `lt`, `lte` - Numeric comparisons
- `like` - Pattern matching with `*` wildcards
- `&` - AND logic for multiple conditions

### Transformations with `transformations.json`

Language-specific transformations are applied automatically:

**translations/default/transformations.json:**
```json
{
  "{ordinal}": {
    "ordinal:gte=10&ordinal:lte=13": "{ordinal}th",
    "ordinal:like=*1": "{ordinal}st",
    "ordinal:like=*2": "{ordinal}nd",
    "ordinal:like=*3": "{ordinal}rd",
    "": "{ordinal}th"
  },
  "{plural}": {
    "plural=1": "",
    "": "s"
  }
}
```

**Usage:**
```php
echo t("You are {rank:ordinal}", ['rank' => 21]);     // "You are 21st"
echo t("Dog{count:plural}", ['count' => 3]);          // "Dogs"
```

**Norwegian transformations.json might override:**
```json
{
  "{ordinal}": {
    "": "{ordinal}."
  }
}
```

Result: `t("You are {rank:ordinal}", ['rank' => 21])` → "You are 21." (Norwegian style)

## Database Migrations

Simple, PHP-based migration system:

### Running Migrations
```bash
composer exec mini migrations  # Run all pending migrations
```

### Creating Migrations

Migrations are PHP files in `migrations/` directory:

```php
<?php
// migrations/001_create_users_table.php

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

### Migration with Seed Data

```php
<?php
// migrations/002_seed_initial_data.php

return function($db) {
    $users = [
        ['admin', 'admin@example.com', password_hash('admin', PASSWORD_DEFAULT)],
        ['user', 'user@example.com', password_hash('user', PASSWORD_DEFAULT)]
    ];

    foreach ($users as [$username, $email, $hash]) {
        $db->exec("INSERT INTO users (username, email, password_hash)
                   VALUES (?, ?, ?)", [$username, $email, $hash]);
    }

    echo "Seeded " . count($users) . " users\n";
};
```

**Migration features:**
- **Sequential execution** - filename-based ordering
- **One-time execution** - tracks completed migrations
- **Simple PHP functions** - no complex migration classes
- **Database agnostic** - works with any PDO-supported database
- **Seed data support** - include test/initial data in migrations

## Enterprise Integration

### Translation Management

The included CLI tool provides professional translation workflows:

- **Token-level parsing** of source code for 100% accuracy
- **Git-integrated** - translations version with your code
- **Context extraction** - translators see surrounding code
- **Validation & QA** - detect orphaned/missing translations
- **Language scaffolding** - create complete language files
- **Multiple export formats** (JSON, CSV) for external tools

### Custom UIs with Claude Code

Instead of shipping a one-size-fits-all admin interface, enterprises can have Claude Code build exactly what they need:

- **Instant customization** - UI built in minutes, not months
- **Perfect integration** - connects to existing tools
- **Zero vendor lock-in** - you own the code
- **Company branding** - matches your design system

### Fault Isolation

File-based architecture provides natural microservices benefits:
- **Independent failure modes** - broken endpoints don't crash the app
- **Progressive deployment** - update files individually
- **Zero-downtime updates** - replace files while serving traffic
- **Natural load balancing** - different files can be on different servers

## Architectural Philosophy & Performance

### Idiomatic PHP: Use $_POST, $_GET Directly

**Mini is different.** We embrace PHP's request-scoped nature rather than abstracting it away:

```php
// Controllers SHOULD use PHP's native request variables directly
$username = $_POST['username'] ?? '';
$userId = $_GET['id'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$files = $_FILES['upload'] ?? [];
```

**Why we don't abstract `$_POST`, `$_GET`, etc.:**

These aren't true superglobals - they're **request-scoped** variables that PHP manages per-request:

- **Zero overhead** - No object wrapping, no middleware layers, no PSR-7 instantiation
- **Transparent** - What you see is what you get, no hidden state transformations
- **Battle-tested** - PHP's request handling has served billions of requests reliably
- **Idiomatic** - Every PHP developer understands these patterns immediately

**The abstraction trap:**

Other frameworks wrap `$_POST` in request objects, but underneath they still use `$_POST`. This adds:
- Object instantiation overhead on every request
- Indirection that obscures simple operations
- Framework-specific APIs to learn and maintain
- No real benefit since PHP already manages request scope correctly

**Mini's philosophy:**

If a framework abstracts `$_POST` but ultimately reads from `$_POST` anyway, we're just adding layers without value. Mini embraces what PHP does well and doesn't apologize for it.

### Our Focus: Native PHP over PSR Abstraction

Mini is intentionally built on PHP's native, battle-tested request-handling model. This means we don't provide abstractions for interfaces like PSR-7, PSR-15, or PSR-11 out of the box.

This is a deliberate design choice that optimizes for:

- **Simplicity** - Fewer concepts to learn and debug
- **Performance** - Eliminates object instantiation overhead on every request
- **Clarity** - Explicit and direct data flow without hidden layers
- **Honesty** - We don't pretend to be framework-agnostic when PHP does the job

**Mini is for developers who:**
- Value directness and transparency
- Understand that request-scoped variables aren't "globals" in the dangerous sense
- Want to write idiomatic PHP, not framework-specific patterns
- Prioritize performance and simplicity over abstraction

**Choose another framework if:**
- PSR-7 compliance is mandatory for your project
- Your team requires framework-agnostic abstractions
- You prefer middleware-based request/response handling

### Authentication: Explicit over Implicit

Mini champions explicit function calls for security. You can see the exact security checks right at the top of your endpoint file:

```php
<?php
// /api/users.php
require_once __DIR__ . '/../vendor/autoload.php';

use function mini\{bootstrap, db};

bootstrap();

require_once __DIR__ . '/../lib/auth.php';  // Your auth functions

MyApp\require_api_access();  // Call where needed

// Your endpoint logic here
$users = db()->query('SELECT * FROM users');
header('Content-Type: application/json');
echo json_encode($users);
```

**Your auth functions (`lib/auth.php`):**
```php
<?php
namespace MyApp;

function require_api_access(): void {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!validate_api_token($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function require_admin(): void {
    session_start();
    if (!isset($_SESSION['user_id']) || !is_admin($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}
```

**Benefits:**
- **Explicit** - you see exactly what auth is required
- **Flexible** - different endpoints can have different requirements
- **Testable** - auth functions can be unit tested independently
- **No magic** - no hidden middleware configuration to debug

### Performance by Design: Sidestepping Complexity

Many modern frameworks rely on Dependency Injection containers to manage complexity. While powerful, these systems introduce their own layers of abstraction, configuration, and potential performance overhead, often requiring a build or cache-compilation step to be fast.

Mini's philosophy is simpler: avoid the need for a container in the first place.

```php
// Direct, efficient access
$user = db()->queryOne('SELECT * FROM users WHERE id = ?', [$userId]);
```

Our approach provides measurable benefits:

- **Zero Overhead** - with no container to build or resolve, every request is leaner
- **Lazy Initialization by Default** - helper functions (`db()`, `cache()`) are lightweight and only initialize their respective services the first time you call them in a request
- **Linear Performance** - application performance doesn't degrade as you add more endpoints, because endpoints are completely isolated
- **Ultimate Simplicity** - you don't need to think about service providers, factories, or autowiring. You just call the function you need when you need it

### Design Philosophy: Pragmatic Object-Oriented Programming

Mini embraces pragmatic OOP where it makes sense:

```php
$db = db();                    // Returns a database instance
$translator = translator();    // Returns a translator instance
$cache = cache('users');       // Returns a cache instance
```

**Our approach to interfaces:**
- We don't create interfaces for everything (no `QueryParserInterface`, `TranslatorInterface`)
- We focus on doing a few things exceptionally well rather than maximum abstraction
- If you have issues with our implementations, we welcome pull requests
- We leave the choice of OOP vs. functional patterns to developers

**Why this works:**
- **Focused scope** - Mini does specific things very well
- **Community-driven improvements** - better implementations come through contributions
- **Developer freedom** - use the patterns that fit your application
- **Less complexity** - no need to learn abstract interfaces for concrete implementations

### Development Velocity

**Mini development workflow:**
1. Create `/api/feature.php`
2. Write business logic with direct PHP
3. Deploy file
4. Feature is live

**Key advantages:**
- **No configuration** - works out of the box
- **No abstractions to learn** - use PHP as intended
- **No build step** - direct deployment
- **No framework coupling** - business logic is portable

### Authorization & Session Management

**Lazy Session Initialization**

Sessions are started automatically only when needed, following Mini's lazy initialization principle:

```php
// These functions automatically handle session startup
function is_logged_in(): bool              // Starts session if needed
function require_login()                   // Calls is_logged_in() → auto-starts session
function require_role(string $role)        // Calls require_login() → auto-starts session

// Usage - no manual session calls needed
require_login();                           // Login required
require_role('system_admin');              // Role-specific access control
```

**Clean Authorization Patterns**

Instead of repetitive access control code:

```php
// Old approach (repetitive)
require_login();
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo render('tpl/403.php', ['title' => 'Access Denied']);
    exit;
}

// Mini approach (clean)
require_role('admin');  // One line handles everything
```

**Benefits:**
- **Automatic session management** - No manual `session_start()` calls needed
- **Centralized authorization** - Consistent access control patterns
- **Performance optimization** - Sessions only started when actually needed
- **Developer friendly** - Less boilerplate, fewer bugs

### CLI Tools & Developer Experience

**Unified Command Interface**

Mini provides professional CLI tools via Composer's standard workflow:

```bash
composer exec mini                          # Show all available commands
composer exec mini translations             # Validate translation files
composer exec mini translations add-missing # Add missing strings automatically
composer exec mini migrations              # Run database migrations
```

**Cross-Platform Support**

The CLI automatically works across all platforms:
- **Linux/macOS**: Uses native executable wrappers
- **Windows**: Provides `.bat` and `.cmd` wrappers
- **Universal**: Falls back to PHP execution

**Extensible Architecture**

Adding new commands requires only:
1. Drop script in `mini/bin/mini-{command}.php`
2. Update CLI dispatcher
3. Commands are automatically discovered

**Development Workflow Benefits:**
- **Discoverable** - `composer exec mini` shows all tools
- **Consistent** - Same interface pattern across all tools
- **Professional** - Matches patterns from Laravel, Symfony, Doctrine
- **Standard** - Uses `composer exec` best practices

## Translation Strategy Guide

### ICU MessageFormat vs. Mini Conditional Translations

**Use ICU MessageFormat for standard internationalization:**

| **Use Case** | **ICU MessageFormat** | **Mini Conditional** |
|-------------|---------------------|---------------------|
| **Pluralization** | ✅ `{count, plural, one{#} other{#}}` | ❌ Don't reinvent |
| **Ordinals** | ✅ `{rank, selectordinal, one{#st} other{#th}}` | ❌ Don't reinvent |
| **Gender/Select** | ✅ `{gender, select, male{he} other{they}}` | ❌ Don't reinvent |
| **Date/Number Format** | ✅ `{date, date, full}` | ❌ Don't reinvent |
| **Multi-variable logic** | ❌ Cannot do | ✅ `count=1&priority=high` |
| **Range conditions** | ❌ Cannot do | ✅ `score:gte=90` |
| **Business rules** | ❌ Cannot do | ✅ `total:gte=50&country=US` |
| **A/B testing** | ❌ Cannot do | ✅ `experiment=variant_a` |

**Decision Tree:**

```
Is this standard i18n (plurals, ordinals, gender, dates)?
├─ YES → Use ICU MessageFormat
└─ NO → Is this business logic with multiple variables?
   ├─ YES → Use Mini conditional translations
   └─ NO → Use controller logic + separate translation keys
```

**Examples:**

```php
// ✅ GOOD: Standard i18n with ICU
t("You have {count, plural, =0{no messages} =1{one message} other{# messages}}")
t("You finished {place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}!")

// ✅ GOOD: Business logic with Mini conditionals
t("shipping_status", ['total' => 75, 'country' => 'US', 'weight' => 10])
// → "🚛 Free shipping to US!" (from conditional JSON)

// ✅ GOOD: Controller logic for complex scenarios
if ($user->isVip() && $cart->hasItems() && $promotion->isActive()) {
    $message = t('vip_promotion_active');
} else {
    $message = t('standard_checkout');
}

// ❌ BAD: Reinventing ICU features
"messages": {
  "count=0": "No messages",
  "count=1": "One message",
  "": "{count} messages"
}
```

### When to Choose Mini

**Mini is ideal when:**
- Performance matters more than abstraction
- Development speed is critical
- Team prefers explicit over implicit
- You want enterprise features without enterprise complexity
- Fault isolation is important
- **Business logic needs to drive translation selection**
- **Non-technical teams need to manage messaging rules**

**Choose another framework when:**
- PSR compliance is mandatory
- Middleware-based architecture is required
- You need framework-specific ecosystem packages
- You require extensive interface abstractions for every component
- **Simple applications without complex business messaging needs**

## License

MIT - Build whatever you want, wherever you want.