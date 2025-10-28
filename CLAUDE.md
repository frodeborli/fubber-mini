# Mini Framework - Development Guide for Claude Code

## Philosophy

The mini framework follows **old-school PHP principles** with modern conveniences:

- **Simple, readable PHP** - No complex abstractions or heavy frameworks
- **Lazy initialization** - Utilities initialize themselves on-demand, not in bootstrap
- **Minimal magic** - Explicit function calls, clear data flow
- **Separation of concerns** - Framework handles core functionality, applications handle business logic
- **AI-friendly architecture** - Designed specifically for efficient Claude Code development
- **Convention over configuration** - Sensible defaults with minimal setup required

## Foundation Layer: The Mini Class

The `Mini` class is the **immutable foundation** of the framework. It auto-executes via Composer's autoload and establishes "ground truth" about the environment once, at the earliest possible moment.

### **Characteristics**

- **Auto-initialization**: Executes `new Mini()` when Composer autoloader runs (via `bootstrap.php`)
- **Immutable singleton**: All properties are `readonly` and set once during construction
- **PSR-11 Container**: Implements `ContainerInterface` for dependency injection
- **Zero required configuration**: Everything auto-detects with sensible defaults via environment variables
- **No config files needed**: Framework uses Mini properties (set from environment), not config files
- **Request-scoped but long-lived ready**: Designed for traditional PHP (request-scoped) but architected for async frameworks (Swoole, RoadRunner, FrankenPHP)
- **Single source of truth**: The ONLY place where project root, document root, and base URL are determined

### **Properties**

```php
Mini::$mini->root             // Project root (where composer.json lives)
Mini::$mini->paths            // InstanceStore of PathsRegistry objects (config, views, migrations, etc.)
Mini::$mini->docRoot          // Web-accessible document root (html/, public/, or DOCUMENT_ROOT)
Mini::$mini->baseUrl          // Base URL with subdirectory detection
Mini::$mini->debug            // Debug mode flag (from DEBUG env var)
Mini::$mini->locale           // Application default locale (for i18n)
Mini::$mini->timezone         // Application default timezone
Mini::$mini->defaultLanguage  // Default language for translation fallbacks (MINI_LANG or 'en')
```

### **Detection Priority**

Each property follows a priority chain from explicit to auto-detected:

**Project Root** (`$root`):
1. `MINI_ROOT` environment variable
2. Composer ClassLoader location (3 levels up from vendor/composer/ClassLoader.php)

**Document Root** (`$docRoot`):
1. `MINI_DOC_ROOT` environment variable
2. `$_SERVER['DOCUMENT_ROOT']` (from web server)
3. `{root}/html/` directory if it exists
4. `{root}/public/` directory if it exists
5. `null` (SimpleRouter will use project root as fallback)

**Base URL** (`$baseUrl`):
1. `MINI_BASE_URL` environment variable
2. Auto-construct from `HTTP_HOST` + `SCRIPT_NAME` subdirectory detection
3. `null` if running in CLI context

**Debug Mode** (`$debug`):
1. `DEBUG` environment variable (truthy check)
2. `false` if not set

**Locale** (`$locale`):
1. `MINI_LOCALE` environment variable
2. `ini_get('intl.default_locale')` from php.ini
3. `'en_GB.UTF-8'` as final fallback

**Timezone** (`$timezone`):
1. `MINI_TIMEZONE` environment variable
2. `date_default_timezone_get()` (PHP's current timezone from php.ini or previously set)

**Default Language** (`$defaultLanguage`):
1. `MINI_LANG` environment variable
2. `'en'` as default

### **Usage Pattern**

Always use `Mini::$mini` to access environment configuration:

```php
// ✅ Correct - Single source of truth
$projectRoot = Mini::$mini->root;
$docRoot = Mini::$mini->docRoot;
$baseUrl = Mini::$mini->baseUrl;
$debug = Mini::$mini->debug;

// ❌ Wrong - Never use these patterns
$projectRoot = dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 3);
$projectRoot = $GLOBALS['app']['root'] ?? ...;
if (defined('mini\DEBUG')) { ... }  // Never use the DEBUG constant
```

### **Critical Rules**

1. **Never auto-detect base URL outside Mini**: If a function needs `baseUrl` and `Mini::$mini->baseUrl` is `null`, throw an exception. Don't try to construct it yourself.

2. **Never use mini\DEBUG constant**: Only use `Mini::$mini->debug` directly. The constant does not exist and should not be defined anywhere.

3. **Single source of truth**: Mini determines environment config once. Other code reads from Mini, never duplicates detection logic.

### **Two Patterns for PHP Files**

Mini supports two distinct patterns:

#### **Pattern 1: Routing Mode (Recommended)**

Enable routing by having `DOC_ROOT/index.php` call `mini\router()`:

```php
// DOC_ROOT/index.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();  // Enables routing, bootstraps framework
```

**Route handlers** in `_routes/` don't need bootstrap:

```php
// _routes/users.php (handles /users)
<?php
header('Content-Type: application/json');
echo json_encode(['users' => db()->query("SELECT * FROM users")->fetchAll()]);
```

**Benefits:**
- ✅ Clean URL routing (`/users`, not `/users.php`)
- ✅ Route handlers not web-accessible (security)
- ✅ No bootstrap needed in route handlers
- ✅ Clear separation: routing logic in `_routes/`, static files in DOC_ROOT

#### **Pattern 2: Standalone PHP Files**

For custom PHP files in DOC_ROOT that don't use routing:

```php
// DOC_ROOT/something.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();  // Required for error handling and output buffering

echo "<h1>Custom Page</h1>";
echo "<p>This is a standalone PHP file</p>";
```

**When to use:**
- Simple sites without routing
- Special-purpose pages (admin tools, status pages)
- Legacy pages being migrated

**What bootstrap() does:**
- **Sets up error/exception handlers** - Converts PHP errors to exceptions, renders error pages
- **Respects existing exception handlers** - Preserves any handler set before bootstrap()
- **Starts output buffering** - Unlimited buffering for exception recovery
- **Parses JSON POST bodies** - Automatically parses `application/json` to `$_POST`
- **Handles `/index.php` redirect** - Redirects `/index.php` → `/` if routing is enabled
- **Idempotent** - Safe to call multiple times (returns immediately after first call)

**Important:** Don't mix patterns! Either use routing (`mini\router()` in index.php) OR standalone files (`mini\bootstrap()` in each file).

**Custom Exception Handlers:**

Mini respects any exception handler you set before calling `bootstrap()`. This allows complete control over error handling:

```php
// app/bootstrap.php (composer autoload file)
set_exception_handler(function(\Throwable $e) {
    // Your custom error handling
    logToSentry($e);
    render_custom_error_page($e);
});

// Later, when bootstrap() is called, your handler is preserved
mini\bootstrap();  // Keeps your custom handler
```

If no exception handler exists, Mini sets its own default handler that:
- Logs exceptions via `error_log()`
- Renders proper error pages from `_errors/` directory
- Shows debug info when `Mini::$mini->debug` is true
- Handles `HttpException` and `AccessDeniedException` specially

## Hooks and Events System

Mini includes a powerful but simple hooks/events system for extending functionality and reacting to lifecycle events.

### **Hook Types**

**1. Event** - Can trigger multiple times
```php
$event = new \mini\Hooks\Event('description');
$event->listen(fn() => echo "Event fired\n");
$event->trigger(); // Can be called many times
```

**2. Trigger** - Fires exactly once (one-time initialization)
```php
$trigger = new \mini\Hooks\Trigger('app-ready');
$trigger->listen(fn() => echo "App is ready\n");
$trigger->trigger(); // First call fires listeners
$trigger->trigger(); // Throws LogicException

// Late subscribers get called immediately:
$trigger->listen(fn() => echo "I'm late!\n"); // Called immediately
```

**3. Filter** - Chain of transformations
```php
$filter = new \mini\Hooks\Filter('sanitize-title');
$filter->listen(fn($title) => strtolower($title));
$filter->listen(fn($title) => str_replace(' ', '-', $title));

$result = $filter->filter("Hello World"); // "hello-world"
```

**4. Handler** - First non-null response wins
```php
$handler = new \mini\Hooks\Handler('route-handler');
$handler->listen(function($path) {
    if ($path === '/special') return 'special-handler.php';
    return null; // Pass to next handler
});

$result = $handler->trigger('/special'); // 'special-handler.php'
```

**5. PerItemTriggers** - Trigger once per source (object or string)
```php
$saved = new \mini\Hooks\PerItemTriggers('model-saved');

// Trigger for specific model instance
$user = new User();
$saved->triggerFor($user, $data);

// Trigger for class name
$saved->triggerFor(User::class, $data);
```

### **Built-in Lifecycle Hooks**

Mini provides two lifecycle hooks for request-level customization:

**1. `Mini::$mini->onRequestReceived`** - Event
```php
// Fires at the very beginning of bootstrap() (each request)
// Use this for: request tracking, logging, early validation

Mini::$mini->onRequestReceived->listen(function() {
    // Request just started
    error_log("Request received: " . $_SERVER['REQUEST_URI']);

    // ✅ Available: $_GET, $_POST, $_SERVER, $_COOKIE
    // ✅ Available: Mini::$mini properties (root, baseUrl, debug, etc.)
    // ❌ NOT Available: db(), auth() - request context not entered yet
    // ❌ CANNOT: Register services (container locked at bootstrap start)
});
```

**2. `Mini::$mini->onAfterBootstrap`** - Event
```php
// Fires at the very end of bootstrap() (each request)
// Use this for: middleware-like logic, setup tasks, feature initialization

Mini::$mini->onAfterBootstrap->listen(function() {
    // Request fully bootstrapped and ready

    // ✅ Available: db(), auth(), all framework functions
    // ✅ Available: Output buffering active (can modify response)
    // ✅ Available: Error handlers configured
    // ❌ CANNOT: Register services (already in Request phase)

    // Example: Start session for all requests
    session();

    // Example: Log authenticated user
    if (isset($_SESSION['user_id'])) {
        error_log("User {$_SESSION['user_id']} accessed {$_SERVER['REQUEST_URI']}");
    }
});
```

### **When to Use Composer Autoload Instead**

For code that needs to run **before any request**, register a file in `composer.json`:

```json
{
    "autoload": {
        "files": [
            "app/bootstrap.php"
        ]
    }
}
```

```php
// app/bootstrap.php (runs once when composer autoload loads)
use mini\Mini;

// ✅ Mini::$mini already exists (Mini's bootstrap.php loaded first)
// ✅ Can register services (still in Bootstrap phase)
// ✅ Can add hook listeners for request lifecycle

// Register custom service
Mini::$mini->addService('MyService', \mini\Lifetime::Singleton, function() {
    return new MyService();
});

// Register request-level hooks
Mini::$mini->onAfterBootstrap->listen(function() {
    // This runs on EVERY request
    setupApplicationFeatures();
});
```

**Key Insight**: Composer loads files AFTER dependency graph traversal (leaf dependencies first). This means:
1. `vendor/fubber/mini/bootstrap.php` runs first (creates `Mini::$mini`)
2. Your `app/bootstrap.php` runs second (`Mini::$mini` available)
3. Request handling begins (hooks fire)

### **Lifecycle Timeline**

```
┌─ COMPOSER AUTOLOAD (once per PHP process)
│  ├─ vendor/fubber/mini/bootstrap.php executes
│  │  └─ new Mini() creates singleton
│  │     └─ Mini::bootstrap() sets up environment
│  └─ app/bootstrap.php executes (your code)
│     ├─ Mini::$mini available
│     ├─ Can register services
│     └─ Can register hook listeners
│
├─ REQUEST STARTS (each request)
│  ├─ mini\bootstrap() or mini\router() called
│  │  ├─ onRequestReceived->trigger() 🔥
│  │  ├─ Mini::$mini->enterRequestContext()
│  │  ├─ Error handlers configured
│  │  ├─ Output buffering started
│  │  ├─ JSON POST parsing
│  │  └─ onAfterBootstrap->trigger() 🔥
│  │
│  ├─ Route handler executes
│  │  └─ Full framework access (db(), auth(), etc.)
│  │
│  └─ Response sent
└─ REQUEST ENDS
```

### **Common Patterns**

**Pattern 1: Request-Level Setup (Middleware-Style)**
```php
// app/bootstrap.php (autoloaded via composer.json)
use mini\Mini;

// Run authentication check on every request
Mini::$mini->onAfterBootstrap->listen(function() {
    session();

    // Check if route requires authentication
    $protectedPaths = ['/admin', '/profile', '/api'];
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    foreach ($protectedPaths as $path) {
        if (str_starts_with($currentPath, $path)) {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
        }
    }
});
```

**Pattern 2: Plugin System**
```php
// Create custom events for your application
class App {
    public static \mini\Hooks\Event $userLoggedIn;
    public static \mini\Hooks\Filter $emailContent;

    public static function init() {
        self::$userLoggedIn = new \mini\Hooks\Event('user-logged-in');
        self::$emailContent = new \mini\Hooks\Filter('email-content');
    }
}

App::init();

// Plugins can hook in
App::$userLoggedIn->listen(function($user) {
    logUserActivity($user);
});

App::$emailContent->listen(function($content) {
    return addEmailFooter($content);
});

// Trigger events
App::$userLoggedIn->trigger($user);
$email = App::$emailContent->filter($emailContent);
```

**Pattern 3: One-Time Initialization**
```php
// Use Trigger for "ready" states
class Database {
    public readonly \mini\Hooks\Trigger $connected;

    public function __construct() {
        $this->connected = new \mini\Hooks\Trigger('db-connected');
    }

    public function connect() {
        // ... connection logic ...
        $this->connected->trigger();
    }
}

$db = new Database();

// Can wait for connection
$db->connected->listen(function() {
    echo "Database ready!\n";
});

$db->connect(); // Listener called

// Late subscriber (if already connected)
$db->connected->listen(function() {
    echo "I'm late but still called!\n"; // Called immediately
});
```

**Pattern 4: Request/Response Modification**
```php
// Create hooks in router
class Router {
    public static \mini\Hooks\Event $beforeRoute;
    public static \mini\Hooks\Event $afterRoute;
    public static \mini\Hooks\Filter $responseBody;
}

Router::$beforeRoute = new \mini\Hooks\Event('before-route');
Router::$afterRoute = new \mini\Hooks\Event('after-route');
Router::$responseBody = new \mini\Hooks\Filter('response-body');

// Usage
Router::$beforeRoute->listen(function() {
    startTimer();
    checkAuthentication();
});

Router::$responseBody->listen(function($body) {
    return compressResponse($body);
});

// In router
Router::$beforeRoute->trigger();
// ... execute route ...
$output = ob_get_clean();
$output = Router::$responseBody->filter($output);
echo $output;
Router::$afterRoute->trigger();
```

### **Advanced Features**

**Once Listeners** - Auto-unsubscribe after first trigger:
```php
$event = new \mini\Hooks\Event('my-event');
$event->once(function() {
    echo "Called only once!\n";
});

$event->trigger(); // Prints message
$event->trigger(); // Doesn't print (already unsubscribed)
```

**Unsubscribing:**
```php
$listener = function() { echo "Hello\n"; };
$event->listen($listener);

// Later...
$event->off($listener); // Removes all instances of this listener
```

**Exception Handling:**
```php
// Configure global exception handler for hooks
\mini\Hooks\Dispatcher::configure(
    deferFunction: fn($callback, ...$args) => $callback(...$args),
    runEventsFunction: fn() => null,
    exceptionHandler: function($exception, $listener, $event) {
        error_log("Hook error in {$event->getDescription()}: {$exception->getMessage()}");
        // Don't re-throw - prevents one bad listener from breaking others
    }
);
```

**Async Integration (Advanced):**
```php
// Integrate with phasync or other async frameworks
\mini\Hooks\Dispatcher::configure(
    deferFunction: fn($callback, ...$args) => \phasync::run($callback, ...$args),
    runEventsFunction: fn() => \phasync::runScheduledFibers(),
    exceptionHandler: fn($e, $listener, $event) => error_log($e)
);
```

### **Performance Considerations**

- **Hooks are fast** - Minimal overhead (~0.3ms per 1M event constructions)
- **Late subscriber optimization** - Triggers remember their data for immediate callback
- **WeakMap usage** - No memory leaks with object-based events
- **Deferred execution** - Events queue by default, batch processed

### **Best Practices**

**✅ DO:**
- Use descriptive names for events: `new Event('user-logged-in')`
- Use Trigger for one-time initialization events
- Use Filter for transformation pipelines
- Document what data your events pass to listeners

**❌ DON'T:**
- Create events inside loops (create once, trigger many times)
- Throw exceptions from listeners unless you have exception handler configured
- Forget to unsubscribe in long-running servers (memory leaks)

### **Integration with Routing**

To add routing hooks, create them in your application bootstrap:

```php
// app/bootstrap.php (autoloaded via composer.json)
namespace App;

class Hooks {
    public static \mini\Hooks\Event $beforeRoute;
    public static \mini\Hooks\Event $afterRoute;

    public static function init() {
        self::$beforeRoute = new \mini\Hooks\Event('before-route');
        self::$afterRoute = new \mini\Hooks\Event('after-route');
    }
}

Hooks::init();

// Add global middleware-style behavior
Hooks::$beforeRoute->listen(function() {
    checkRateLimit();
    enableCors();
});
```

Then trigger in your routes:
```php
// _routes/api/users.php
<?php
\App\Hooks::$beforeRoute->trigger();

$users = db()->query("SELECT * FROM users")->fetchAll();
header('Content-Type: application/json');
echo json_encode($users);

\App\Hooks::$afterRoute->trigger();
```

Or integrate into Mini's router for automatic triggering.

## Plugin/Bundle Ecosystem

### **Path Registry System with InstanceStore**

Mini uses an InstanceStore of PathsRegistry objects, enabling plugins and bundles to register searchable path hierarchies. Applications and bundles can add path registries for any resource type: config files, views, migrations, translations, etc.

**Config Path Priority:**
1. Application config (`{root}/config/`)
2. Plugin/bundle configs (added via `addPath()`)
3. Framework config (`vendor/fubber/mini/config/`)

**Example: Creating a Bundle**
```php
// vendor/fubber/mini-file-cms/bootstrap.php
<?php
// This file is autoloaded by Composer after Mini's bootstrap.php

// Add this bundle's config as a fallback
Mini::$mini->paths->config->addPath(__DIR__ . '/config');

// Add this bundle's views with priority search
Mini::$mini->paths->views = new PathsRegistry(__DIR__ . '/views');

// Now Mini::$mini->loadConfig('cms.php') will search:
// 1. /project/config/cms.php (application can override)
// 2. /vendor/fubber/mini-file-cms/config/cms.php (bundle default)
// 3. /vendor/fubber/mini/config/cms.php (framework fallback)

// Applications can override views too:
// Mini::$mini->paths->views->addPath(Mini::$mini->root . '/views')
```

**Example: Using a Bundle**
```php
// Application's composer.json
{
    "require": {
        "fubber/mini": "^1.0",
        "fubber/mini-file-cms": "^1.0"
    }
}

// The bundle automatically adds its config paths
// Application can override any bundle config by creating config files
```

**Bundle Structure:**
```
vendor/fubber/mini-file-cms/
├── bootstrap.php          # Registers config paths
├── config/
│   ├── cms.php           # Bundle's default config
│   └── routes.php        # Bundle's default routes
└── src/
    └── Cms.php
```

### **Composer Autoload Order**

Composer ensures proper initialization order:
```
1. vendor/fubber/mini/bootstrap.php        # Mini::$mini created
2. vendor/fubber/mini/functions.php        # Framework helpers loaded
3. vendor/fubber/mini-file-cms/bootstrap.php  # Bundle adds paths
4. {your-code}                             # Application runs
```

This guarantees `Mini::$mini` exists before any bundle tries to call `paths->config->addPath()`.

### **Best Practices for Bundle Authors**

**✅ DO:**
- Add your config path in your package's bootstrap.php via `Mini::$mini->paths->config->addPath()`
- Create path registries for your bundle resources: views, migrations, etc.
- Provide sensible defaults in your config files
- Document which configs can be overridden
- Use `Mini::$mini->loadConfig('your-bundle.php', default: [...])` for optional configs

**❌ DON'T:**
- Assume application has your config (always provide defaults)
- Modify Mini's other properties (they're readonly)
- Add paths from anywhere except your bootstrap file
- Depend on load order between peer dependencies
- Try to overwrite existing path registries (use addPath() instead, or __set will throw)

**Example: Bundle with Multiple Resource Types**
```php
// vendor/fubber/mini-file-cms/bootstrap.php
Mini::$mini->paths->config->addPath(__DIR__ . '/config');
Mini::$mini->paths->{'cms-views'} = new PathsRegistry(__DIR__ . '/views');
Mini::$mini->paths->{'cms-migrations'} = new PathsRegistry(__DIR__ . '/migrations');
```

**Example: Well-Designed Bundle Config**
```php
// In your bundle's config/cms.php
return [
    'storage_path' => Mini::$mini->root . '/storage/cms',
    'cache_enabled' => true,
    'theme' => 'default',
    // ... sensible defaults
];

// In your bundle's code
$config = Mini::$mini->loadConfig('cms.php');  // Will find your default
// Application can override by creating their own config/cms.php
```

### **Application Usage of Path Registries**

Applications can register any type of path hierarchy for their resources:

```php
// app/bootstrap.php (autoloaded via composer.json files key)

// Register views with priority search
Mini::$mini->paths->views = new PathsRegistry(Mini::$mini->root . '/views');

// Bundles can add their views as fallbacks
// (done in bundle's bootstrap.php)
Mini::$mini->paths->views->addPath(__DIR__ . '/vendor-views');

// Register migrations
Mini::$mini->paths->migrations = new PathsRegistry(Mini::$mini->root . '/migrations');

// Register translations
Mini::$mini->paths->translations = new PathsRegistry(Mini::$mini->root . '/translations');
```

**Finding Files in Path Registries:**
```php
// Find first match (priority order)
$viewPath = Mini::$mini->paths->views->findFirst('home.php');
// Searches: /project/views/home.php, then bundle paths, then framework

// Get all registered paths
$searchPaths = Mini::$mini->paths->views->getPaths();

// Check if registry exists (via property_exists or try/catch)
try {
    $viewPath = Mini::$mini->paths->views->findFirst('home.php');
    // ... use views registry
} catch (\Exception $e) {
    // Registry doesn't exist, __get threw exception
}
```

## Internationalization (i18n) Architecture

### **The "Request Global" Mental Model**

PHP developers are familiar with "request globals": `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION`. These appear global but are actually request-scoped:
- **Traditional SAPI**: Fresh for each request, automatic cleanup
- **Long-running apps**: Each fiber/process gets its own copy

**Mini treats locale/timezone the same way**:
- `\Locale::setDefault()` - Request-scoped locale (like `$_GET`)
- `date_default_timezone_set()` - Request-scoped timezone (like `$_POST`)

Mini sets these immediately during composer autoload from application defaults. Applications can override them per-request.

### **Mini Sets Defaults Immediately**

```php
// In Mini::bootstrap() - runs automatically via composer autoload
\Locale::setDefault($this->locale);           // From MINI_LOCALE env or php.ini
date_default_timezone_set($this->timezone);   // From MINI_TIMEZONE env or PHP default
```

This happens **before any application code runs**, establishing consistent defaults.

### **Applications Override Per-Request**

Applications can customize in their bootstrap file (autoloaded via composer.json):

```php
// app/bootstrap.php (in composer.json "autoload.files")

// Load user preferences from database/session/cookie
$user = getCurrentUser();  // Your application code

// Override PHP's request globals (just like setting $_SESSION)
if ($user && $user->locale) {
    \Locale::setDefault($user->locale);              // e.g., 'de_DE'
}

if ($user && $user->timezone) {
    date_default_timezone_set($user->timezone);      // e.g., 'Europe/Berlin'
}

// Optional: Configure translator
$languageCode = \Locale::getPrimaryLanguage(\Locale::getDefault());  // Extracts 'de' from 'de_DE'
translator()->trySetLanguageCode($languageCode);
```

**That's it!** No Mini wrapper functions needed. Just call PHP's standard functions directly.

### **How i18n Components Get Locale**

**Factory Functions** use `\Locale::getDefault()`:
```php
// All use the request-scoped \Locale::getDefault()
$formatter = numberFormatter();  // Uses current request locale
$formatter = intlDateFormatter(); // Uses current request locale
$formatter = messageFormatter($pattern); // Uses current request locale
```

**Fmt Class** delegates to factory functions:
```php
Fmt::currency(19.99, 'EUR');  // Uses request locale → numberFormatter()
Fmt::dateShort($date);        // Uses request locale → intlDateFormatter()
```

**Translator** has its own language code:
```php
translator()->setLanguageCode('nb');  // Independent of locale
translator()->trySetLanguageCode($_SESSION['language']);  // Persists to session
```

**Collator** - create when needed for specific use cases:
```php
// Collation is use-case specific, not application-wide
// Create collators configured for your specific needs

// Example: Sorting product names with numeric collation
$collator = new \Collator(Mini::$mini->locale);
$collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
usort($products, fn($a, $b) => $collator->compare($a->name, $b->name));

// Example: Accent-insensitive user search
$searchCollator = new \Collator(Mini::$mini->locale);
$searchCollator->setStrength(\Collator::PRIMARY); // Ignores accents
```

### **Concurrency Model: Runtime Responsibility**

**Mini's Architectural Principle**: Mini does NOT manage concurrency or context switching. That's the runtime's job.

PHP's "request globals" (`$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`) are request-scoped:

| Traditional SAPI | Concurrent Runtimes (phasync, ReactPHP, Swoole) |
|------------------|--------------------------------------------------|
| Fresh per request | Runtime provides per-fiber/promise isolation |
| Auto cleanup | Runtime context-switches ALL request globals |
| No framework involvement | Framework trusts runtime's context management |

**Concurrent runtimes MUST juggle ALL request globals when switching context:**
- `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`
- `\Locale::setDefault()` / `\Locale::getDefault()`
- `date_default_timezone_set()` / `date_default_timezone_get()`

**Mini expects this and relies on it.**

**Example: phasync or similar Fiber-based runtime**
```php
// Runtime's responsibility - NOT Mini's
class FiberScheduler {
    public function switchToFiber(Fiber $fiber, RequestContext $context) {
        // Restore ALL request globals for this fiber
        $_GET = $context->get;
        $_POST = $context->post;
        $_SESSION = $context->session;
        $_COOKIE = $context->cookie;

        // Restore locale/timezone (same pattern!)
        \Locale::setDefault($context->locale);
        date_default_timezone_set($context->timezone);

        $fiber->resume();
    }
}
```

**Example: ReactPHP promise-based runtime**
```php
// Runtime captures context when promise starts
$promise = new Promise(function($resolve) use ($currentLocale, $currentTimezone) {
    // Each promise chain operates in isolated context
    \Locale::setDefault($currentLocale);
    date_default_timezone_set($currentTimezone);

    // Do async work...
    $resolve($result);
});
```

### **Separation of Concerns**

| Responsibility | Who Handles It | Example |
|----------------|----------------|---------|
| **Application defaults** | Mini (via env vars) | `MINI_LOCALE=en_GB` |
| **Request-specific values** | Application code | `\Locale::setDefault($user->locale)` |
| **Context isolation** | Concurrent runtime | phasync, ReactPHP, Swoole |
| **Context switching** | Concurrent runtime | Restore `$_GET`, `$_POST`, `\Locale`, etc. |

**Mini's philosophy**: Provide defaults, then get out of the way. Let PHP work as designed.

### **Key Insight: "Request Globals" Not "Super Globals"**

PHP's so-called "super globals" are actually **request globals** - scoped to the current request:

```php
// All of these are request-scoped, not truly global:
$_GET['page'] = 'home';               // Request global
$_POST['action'] = 'submit';          // Request global
$_SESSION['user_id'] = 123;           // Request global
$_COOKIE['theme'] = 'dark';           // Request global

// Mini treats these identically:
\Locale::setDefault('de_DE');         // Request global (same pattern!)
date_default_timezone_set('UTC');     // Request global (same pattern!)
```

**Characteristics:**
- Fresh per request (traditional SAPI) or per fiber/promise (concurrent runtimes)
- Set at request start, used throughout request
- No cross-request pollution if runtime isolates properly
- Developers set them using standard PHP functions

**This is not a Mini abstraction - Mini uses PHP exactly as designed.**

### **Configuration Flow**

```
1. COMPOSER AUTOLOAD (Immediate)
   ├─ new Mini() executed
   ├─ Reads: MINI_LOCALE env → php.ini → 'en_GB.UTF-8'
   ├─ Reads: MINI_TIMEZONE env → PHP default
   ├─ Sets: \Locale::setDefault(Mini::$mini->locale)
   │        date_default_timezone_set(Mini::$mini->timezone)
   └─ Loads: Application bootstrap files (composer.json "autoload.files")

2. APPLICATION BOOTSTRAP (optional app/bootstrap.php)
   ├─ Loads: User preferences (database/session/cookie)
   ├─ Optionally overrides: \Locale::setDefault($user->locale)
   └─ Optionally overrides: date_default_timezone_set($user->timezone)

3. REQUEST HANDLING
   ├─ numberFormatter() → uses \Locale::getDefault()
   ├─ intlDateFormatter() → uses \Locale::getDefault()
   ├─ Fmt::currency() → uses \Locale::getDefault()
   └─ All formatting respects current locale
```

**Storage vs Runtime:**
```php
Mini::$mini->locale              // Immutable application default (stored)
\Locale::getDefault()            // Current request locale (runtime)

Mini::$mini->timezone            // Immutable application default (stored)
date_default_timezone_get()      // Current request timezone (runtime)
```

### **Practical Implications for Developers**

**✅ DO:**
- Use `\Locale::setDefault()` and `date_default_timezone_set()` freely in your application
- Set them in your bootstrap file based on user preferences
- Trust that concurrent runtimes will isolate them properly
- Use all formatting functions (`Fmt::currency()`, `numberFormatter()`, etc.) knowing they respect current locale

**❌ DON'T:**
- Look for Mini wrapper functions for locale/timezone (they don't exist by design)
- Try to manage concurrency yourself (trust the runtime)
- Store locale/timezone in `$GLOBALS` or static variables (use PHP's built-in functions)
- Worry about cross-request pollution (runtime handles isolation)

**Example: Complete Request Lifecycle**
```php
// 1. Composer autoload (happens once)
new Mini();  // Sets \Locale::setDefault(Mini::$mini->locale)

// 2. app/bootstrap.php (optional, autoloaded via composer.json)
$user = getCurrentUser();
if ($user->locale) {
    \Locale::setDefault($user->locale);  // Override for this request
}

// 3. Your application code (uses current locale)
echo Fmt::currency(19.99, 'EUR');              // Formats with user's locale
echo intlDateFormatter()->format(new DateTime()); // Formats with user's locale

// 4. Concurrent runtime (if using phasync/ReactPHP/Swoole)
// Runtime automatically switches locale when switching fibers/promises
// Developer doesn't need to do anything special!
```

## Code Style & Standards

### **General Rules**
- **No comments** unless explicitly requested or documenting public APIs
- **Descriptive variable names** over comments
- **Single responsibility** - Each class/method does one thing well
- **Null-safe operations** - Always handle null/missing values gracefully

### **Error Handling**
- **Graceful degradation** - Return sensible defaults rather than crashing
- **Clear error messages** - `[missing variable 'name']`, `[unknown filter 'invalid']`
- **Silent fallbacks** - Log errors internally but don't break user experience

## File Organization

### **Core Structure**
```
mini/
├── src/                    # Core framework classes
│   ├── Util/              # Utility classes (QueryParser, EnvFileParser)
│   ├── Cache/             # Cache implementations
│   ├── Mini.php           # Foundation layer (immutable environment config)
│   ├── SimpleRouter.php   # Routing system
│   ├── Translator.php     # Translation system (uses ICU MessageFormatter)
│   ├── Fmt.php           # Localized formatting
│   └── DB.php            # Database abstraction
├── tests/                 # Test files
├── bootstrap.php          # Auto-executes Mini singleton via Composer
├── functions.php          # Global helper functions
└── CLAUDE.md             # This file
```

### **Application Structure**

```
project/
├── _routes/              # Route handlers (not web-accessible)
│   ├── index.php        # / route
│   ├── users.php        # /users route
│   ├── api/
│   │   └── posts.php    # /api/posts route
│   └── _routes.php      # Optional: pattern-based routing
├── _config/              # Configuration files (not web-accessible)
│   └── bootstrap.php    # Optional: custom initialization
├── _errors/              # Error page templates (not web-accessible)
│   ├── 401.php          # Unauthorized error page
│   ├── 403.php          # Forbidden error page
│   ├── 404.php          # Not found error page
│   └── 500.php          # Internal server error page
├── html/ or public/      # Document root (web-accessible files only)
│   ├── index.php        # Entry point: calls mini\router()
│   ├── assets/          # CSS, JS, images
│   └── ...              # Static files only
├── translations/        # Translation files (not web-accessible)
├── vendor/              # Composer dependencies (not web-accessible)
└── .env                 # Environment variables (not web-accessible)
```

**Security Best Practice**: All framework directories start with `_` and are stored in project root (not web-accessible). This works even when `ROOT === DOC_ROOT` for simpler hosting providers. Only `html/` or `public/` contents are web-accessible.

**Clear Separation**:
- **_routes/** - Route handlers (no bootstrap needed)
- **_config/** - Configuration files
- **_errors/** - Error page templates
- **DOC_ROOT/** - Static files + entry point only

### **Test File Naming**
- `{ClassName}.php` - Tests entire class (e.g., `QueryParser.php`)
- `{ClassName}.{feature}.php` - Tests specific feature (e.g., `Translator.conditional.php`)
- `{functionName}.php` - Tests for specific function in the mini\ namespace.

## Architectural Patterns

### **PSR-11 Dependency Injection Container**

The Mini class implements `Psr\Container\ContainerInterface`, providing a lightweight service container with three lifetime modes:

#### **Service Lifetimes**

```php
enum Lifetime {
    Singleton;  // One instance for entire application (stored in Mini::$mini->instanceCache[$mini])
    Scoped;     // One instance per request (stored in Mini::$mini->instanceCache[getRequestScope()])
    Transient;  // New instance every time
}
```

#### **Service Registration**

Services are registered by feature modules in their `functions.php` files:

```php
// src/I18n/functions.php
Mini::$mini->addService(Translator::class, Lifetime::Singleton, function() {
    return new Translator($translationsPath);
});
```

**When it runs**: Feature `functions.php` files are loaded AFTER `bootstrap.php` via Composer autoloader, so `Mini::$mini` is always available.

**Smart singleton design**: Translator is Singleton (not Scoped) because it reads locale dynamically from `\Locale::getDefault()` instead of storing it. Translation file cache is shared across requests for better performance.

#### **Service Retrieval**

```php
// Via helper functions (public API)
$translator = \mini\I18n\translator();  // Returns instance from container
$fmt = \mini\fmt();                     // Returns instance from container
$logger = \mini\log();                  // Returns instance from container

// Direct container access (advanced)
$service = Mini::$mini->get(MyService::class);
$exists = Mini::$mini->has(MyService::class);
```

#### **Architecture Benefits**

- **Modular**: Each feature registers its own services
- **No spaghetti**: Mini class doesn't know about I18n, Logger, etc.
- **Automatic cleanup**: Scoped services garbage-collected when request ends (WeakMap)
- **PSR-11 compliant**: Standard interface for interoperability

### **Helper Function Pattern**

Core utilities are accessed via namespaced global functions that delegate to the container:

```php
// Public API (mini\ namespace)
function t(string $text, array $vars = []): Translatable;  // Translation
function fmt(): Fmt;                                        // Formatting shortcuts
function log(): LoggerInterface;                           // Logging

// Advanced: Direct container access
Mini::$mini->get(Translator::class);  // Get translator for language switching
Mini::$mini->get(Fmt::class);         // Same as fmt()
Mini::$mini->get(Logger::class);      // Same as log()
```

### **Configuration Priority**
1. URL parameters (e.g., `?lang=de`)
2. User preferences (database)
3. Browser detection (Accept-Language)
4. Framework defaults

## Routing System

### **Entry Point Pattern**

To enable routing, create `DOC_ROOT/index.php` that calls `mini\router()`:

```php
// DOC_ROOT/index.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();  // Enables routing and bootstraps framework
```

**What mini\router() does:**
1. Sets global flag that routing is enabled
2. Calls `bootstrap()` to set up error handling and output buffering
3. Delegates URL routing to SimpleRouter
4. Routes loaded from `_routes/` directory

### **Route Handler Pattern**

Route handlers in `_routes/` don't need to call `bootstrap()`:

```php
// _routes/users.php (handles /users)
<?php
header('Content-Type: application/json');
echo json_encode(['users' => [...]]);
```

```php
// _routes/api/posts.php (handles /api/posts)
<?php
$posts = db()->query("SELECT * FROM posts")->fetchAll();
header('Content-Type: application/json');
echo json_encode($posts);
```

**Benefits:**
- ✅ No bootstrap needed (router already called it)
- ✅ Clean, minimal route handlers
- ✅ Not web-accessible (security)
- ✅ Clear URL-to-file mapping

### **Three-Tier Routing Priority**

Mini uses a hierarchical routing system:

1. **File-based routing** (highest priority) - Direct URL-to-file mapping in `_routes/`
2. **Hierarchical `_routes.php`** (middle priority) - Pattern matching and catch-all handlers
3. **Global `_config/routes.php`** (lowest priority) - Application-wide routes

**File-based routing examples:**
- `/` → `_routes/index.php`
- `/users` → `_routes/users.php`
- `/api/posts` → `_routes/api/posts.php`
- `/users/` → `_routes/users/index.php`

**Depth-first search for `_routes.php`:** For `/api/posts/123`, Mini checks:
- `_routes/api/posts/_routes.php` (most specific)
- `_routes/api/_routes.php`
- `_routes/_routes.php` (least specific)
- `_config/routes.php` (global fallback)

### **Hierarchical `_routes.php` Files**

Files named `_routes.php` in `_routes/` subdirectories can return route arrays or RequestHandlers:

**1. Route arrays (traditional pattern matching):**
```php
// _routes/blog/_routes.php
return [
    "/blog" => "blog/index.php",  // Relative to _routes/
    "/blog/{slug}" => fn($slug) => "blog/post.php?slug=$slug",
    "/blog/{year:\d{4}}/{month:\d{2}}" => fn($year, $month) =>
        "blog/archive.php?year=$year&month=$month",
];
```


### **Program Flow**

Understanding the execution flow:

```
1. Web server → DOC_ROOT/index.php
   ├─ require autoload.php
   └─ mini\router() called
      ├─ Sets $GLOBALS['mini_routing_enabled'] flag
      ├─ Calls bootstrap() (error handlers, output buffering)
      └─ Creates SimpleRouter and calls handleRequest()

2. SimpleRouter searches for routes
   ├─ File-based: _routes/users.php → /users
   ├─ Hierarchical: _routes/api/_routes.php for /api/*
   └─ Global: _config/routes.php fallback

3. Route handler executed
   ├─ _routes/users.php included
   ├─ Bootstrap already done - all framework functions available
   ├─ Can call db(), t(), fmt(), etc.
   └─ Uses header() and echo to output response

4. Output sent to browser
   └─ Headers + body sent via PHP's standard output
```

**Key insight:** Bootstrap is called immediately by `mini\router()`, so route handlers have full framework access from the start.

## Middleware Pattern - The Mini Way

Mini doesn't have a complex middleware system like Laravel or Symfony. Instead, it embraces **PHP's native capabilities** for request/response processing. This chapter shows you how to accomplish common middleware tasks using built-in PHP features.

### **Philosophy: Functions Over Frameworks**

Most "middleware" tasks can be accomplished with simple function calls:

```php
// _routes/admin/users.php
<?php
requireAuth();        // Just a function call
checkRateLimit();     // Another function call
// ... rest of your route
```

**Why this is better:**
- ✅ Explicit - you see exactly what's running
- ✅ Simple - no magic, no hidden execution
- ✅ Debuggable - step through with a debugger
- ✅ Flexible - call conditionally, pass parameters

### **Built-in Features**

Mini provides these out-of-the-box:

**1. JSON Request Body Parsing**
```php
// Automatically handled in bootstrap()
// Content-Type: application/json → parsed to $_POST

// Client sends:
// POST /api/users
// Content-Type: application/json
// {"name": "John", "email": "john@example.com"}

// Your route handler:
$name = $_POST['name'];   // "John"
$email = $_POST['email']; // "john@example.com"
```

**2. Unlimited Output Buffering**
```php
// Started in bootstrap(), flushed in router()
// Buffer size: unlimited (never auto-flushes)
// Allows error recovery by discarding buffer
```

### **Pattern 1: "Before Request" Processing**

For code that runs before your route handler:

```php
// helpers.php (autoloaded via composer.json)
function requireAuth(): void {
    session();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function checkRateLimit(int $maxRequests = 100): void {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = "rate_limit:$ip";

    $count = cache()->get($key, 0);
    if ($count >= $maxRequests) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded']);
        exit;
    }

    cache()->set($key, $count + 1, 3600);
}

// Route usage:
// _routes/api/users.php
<?php
requireAuth();
checkRateLimit(50);

// Your actual logic here
$users = db()->query("SELECT * FROM users")->fetchAll();
header('Content-Type: application/json');
echo json_encode(['users' => $users]);
```

### **Pattern 2: Output Transformation with ob_start()**

For processing the response body after it's generated:

**Example: Gzip Compression**
```php
// _routes/large-response.php
<?php
// Start compression buffer
ob_start('ob_gzhandler');

// Generate large response
$data = generateLargeDataset();
header('Content-Type: application/json');
echo json_encode($data);

// Compression applied automatically when buffer flushes
ob_end_flush();
```

**Example: JSON Envelope Wrapper**
```php
// Create a custom output handler
function jsonEnvelopeHandler(string $buffer): string {
    // Don't process if not JSON
    if (!str_contains(headers_list()[0] ?? '', 'application/json')) {
        return $buffer;
    }

    // Wrap response in envelope
    $wrapped = [
        'success' => true,
        'data' => json_decode($buffer, true),
        'timestamp' => time()
    ];

    return json_encode($wrapped);
}

// _routes/api/posts.php
<?php
ob_start('jsonEnvelopeHandler');

$posts = db()->query("SELECT * FROM posts")->fetchAll();
header('Content-Type: application/json');
echo json_encode($posts);

ob_end_flush();

// Client receives:
// {"success": true, "data": [...posts...], "timestamp": 1234567890}
```

**Example: Global Output Handler**
```php
// app/bootstrap.php (autoloaded via composer.json)
<?php
// Register global output handler that runs for all routes
ob_start(function($buffer) {
    // Add security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');

    // Minify HTML (simple example)
    if (str_contains(headers_list()[0] ?? '', 'text/html')) {
        $buffer = preg_replace('/\s+/', ' ', $buffer);
    }

    return $buffer;
});
```

### **Pattern 3: Header Manipulation**

PHP provides complete header control:

**Available Functions:**
```php
header('X-Custom-Header: value');           // Set header
header('Content-Type: application/json');   // Set content type
header_remove('X-Powered-By');              // Remove header
$headers = headers_list();                  // Get all headers
$sent = headers_sent($file, $line);         // Check if sent
```

**Example: CORS Headers**
```php
// helpers.php
function enableCors(array $origins = ['*']): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array('*', $origins) || in_array($origin, $origins)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// _routes/api/posts.php
<?php
enableCors(['https://example.com', 'https://app.example.com']);

$posts = db()->query("SELECT * FROM posts")->fetchAll();
header('Content-Type: application/json');
echo json_encode($posts);
```

**Example: Security Headers**
```php
// helpers.php
function setSecurityHeaders(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Call in app/bootstrap.php to apply globally
setSecurityHeaders();
```

### **Pattern 4: Nested Output Buffers**

PHP supports multiple levels of output buffering:

```php
// _routes/complex.php
<?php
// Outer buffer: Compression
ob_start('ob_gzhandler');

// Inner buffer: HTML minification
ob_start(function($buffer) {
    return preg_replace('/\s+/', ' ', $buffer);
});

// Generate content
?>
<html>
    <body>
        <h1>Hello World</h1>
    </body>
</html>
<?php

// Flush inner buffer (minify) into outer buffer (compress)
ob_end_flush();

// Flush outer buffer (send compressed, minified HTML)
ob_end_flush();
```

### **Pattern 5: Content Type Handlers**

Handle different content types beyond JSON:

```php
// helpers.php
function parseXmlBody(): void {
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/xml')) {
        $xml = file_get_contents('php://input');
        $_POST = json_decode(json_encode(simplexml_load_string($xml)), true);
    }
}

// Call in app/bootstrap.php (autoloaded via composer.json)
parseXmlBody();
```

### **Pattern 6: Streaming Large Files**

For large file downloads, exit Mini's buffer and stream directly:

```php
// _routes/download.php
<?php
requireAuth();

$file = '/path/to/large-file.pdf';

if (!file_exists($file)) {
    throw new \mini\Http\NotFoundException('File not found');
}

// Exit Mini's output buffer to stream directly
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Send headers
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="file.pdf"');
header('Content-Length: ' . filesize($file));

// Stream file in chunks (no memory buffering)
readfile($file);
exit;
```

### **Performance Considerations**

**Output Buffer Size:**
- Mini uses unlimited buffer (size 0) by default
- Good for most applications (typical responses < 1MB)
- For very large responses, use streaming pattern above

**Buffer Overhead:**
- Minimal for typical web responses
- PHP's `ob_start()` is highly optimized
- Only concern: memory for buffered content

**When to Avoid Buffering:**
- Streaming large files (> 10MB)
- Server-sent events (SSE)
- Long-polling endpoints
- Real-time data feeds

### **Common Patterns Summary**

| Task | Pattern | Example |
|------|---------|---------|
| Authentication | Function call | `requireAuth()` |
| Rate limiting | Function call | `checkRateLimit()` |
| JSON parsing | Built-in | Automatic |
| Compression | Output buffer | `ob_start('ob_gzhandler')` |
| Response wrapping | Output callback | `ob_start($callback)` |
| Security headers | Header functions | `header('X-Frame-Options: ...')` |
| CORS | Header functions | `enableCors()` |
| Large files | Streaming | `readfile()` + exit buffer |

### **Why No Middleware Class?**

You might ask: "Why doesn't Mini have a `MiddlewareInterface` like other frameworks?"

**Answer:** Because you don't need it.

Every middleware pattern can be accomplished with:
1. **Function calls** - For before-request logic
2. **Output buffering** - For after-response processing
3. **Header functions** - For header manipulation
4. **Exit/return** - For short-circuiting

This keeps Mini:
- ✅ Simple and readable
- ✅ Easy to debug
- ✅ No hidden execution order
- ✅ True to old-school PHP

**The Mini way is: Use PHP's features, don't abstract them away.**

## Internationalization Factory System

### **Core Principle**
Mini provides factory functions that give developers access to properly configured PHP intl classes while maintaining consistent locale behavior across the application.

### **Factory Functions**
```php
// Formatter factories (use config files for customization)
function numberFormatter(?string $locale = null, int $style = NumberFormatter::DECIMAL): NumberFormatter
function messageFormatter(string $pattern, ?string $locale = null): MessageFormatter
function intlDateFormatter(?int $dateType = IntlDateFormatter::MEDIUM, ?int $timeType = IntlDateFormatter::SHORT, ?string $locale = null, ?string $timezone = null, ?string $pattern = null): IntlDateFormatter

// Convenient stateless formatter
function fmt(): Fmt  // Returns stateless instance, all methods are static
```

**Note:** For locale utilities, use PHP's native `\Locale` class directly:
```php
$currentLocale = \Locale::getDefault();                      // Get current locale
$language = \Locale::getPrimaryLanguage($currentLocale);     // Get language code ('en' from 'en_US')
$region = \Locale::getRegion($currentLocale);                // Get region code ('US' from 'en_US')
$components = \Locale::parseLocale($currentLocale);          // Parse into components
$canonical = \Locale::canonicalize($locale);                 // Canonicalize locale string
```

### **Usage Patterns**

**Direct PHP intl usage with consistent locale:**
```php
$formatter = numberFormatter('nb_NO', NumberFormatter::CURRENCY);
echo $formatter->formatCurrency(19.99, 'NOK'); // "kr 19,99"

$dateFormatter = intlDateFormatter(IntlDateFormatter::FULL, IntlDateFormatter::SHORT, 'nb_NO');
echo $dateFormatter->format(new DateTime()); // "torsdag 26. september 2024 kl. 14:30"
```

**Convenient shortcuts for common cases:**
```php
echo Fmt::currency(19.99, 'NOK');     // Uses current locale automatically
echo Fmt::dateShort(new DateTime());  // Uses current locale automatically
echo Fmt::percent(0.75, 1);          // "75.0%"
```

### **Configuration Files**
Each factory can be customized via config files in `mini/config/`:
- `number-formatter.php` - Custom NumberFormatter configuration
- `message-formatter.php` - Custom MessageFormatter configuration
- `intl-date-formatter.php` - Custom IntlDateFormatter configuration

**Example config file:**
```php
// mini/config/number-formatter.php
return function (string $locale, int $style): NumberFormatter {
    $formatter = new NumberFormatter($locale, $style);
    // Custom configuration for your app
    $formatter->setAttribute(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, ' ');
    return $formatter;
};
```

## Translation System Architecture

### **Key Components**
- **Translatable objects** - Immutable translation requests
- **ICU MessageFormatter** - Industry-standard variable interpolation with plurals, ordinals, dates, numbers
- **QueryParser** - Condition matching for complex business logic in conditional translations

### **Translation Flow**
1. `t("Hello {name}", ['name' => 'World'])` creates Translatable
2. Translator loads translation files with fallback chain
3. ICU MessageFormatter processes variables with locale-specific formatting

### **ICU MessageFormat**
```php
// Plurals
echo t("{count, plural, =0{no items} one{# item} other{# items}}", ['count' => 3]);

// Ordinals
echo t("You finished {place, selectordinal, one{#st} two{#nd} few{#rd} other{#th}}", ['place' => 21]);

// Format values in PHP before passing
echo t("Price: {amount}", ['amount' => fmt()->currency(19.99, 'USD')]);
```

## Database Integration

### **QueryParser Database Mapping**
QueryParser conditions can be converted to SQL WHERE clauses:
- `name=john` → `name = 'john'`
- `age:gte=18` → `age >= 18`
- `count:like=*1` → `count LIKE '%1'` (future enhancement)

### **Database-Friendly Design**
- **Indexable queries** - Avoid `neq`, `mod` operators
- **Use evaluation order** instead of complex boolean logic
- **SQLite3 semantics** - Type coercion and comparison rules

## Testing Conventions

### **Test Structure**
```php
function test(string $description, callable $test): void
function assertEqual($expected, $actual, string $message = ''): void
```

### **Autoloader Pattern**
```php
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');
```

### **Test Categories**
- **Basic functionality** - Core features work correctly
- **Edge cases** - Handle unusual inputs gracefully
- **Error conditions** - Proper error messages and fallbacks
- **Integration** - Components work together properly
- **Future-proofing** - Infrastructure for planned features

## Future Enhancement Architecture

### **Planned Features**
The framework is architected to support:

1. **`like` Operator** - Pattern matching (`count:like=*1`)
2. **Function System** - Mathematical operations (`mod(count,10)`)
3. **Variable References** - Cross-variable comparisons (`age:gt={minimum}`)
4. **Complex Conditionals** - Nested boolean logic

### **Extension Points**
- **QueryParser operators** - Add via `$allowedOperators` array
- **Fmt formatters** - Add methods to Fmt class for domain-specific formatting
- **Cache adapters** - Implement SimpleCacheInterface

## Best Practices for Claude Code Development

### **When Adding New Features**
1. **Write tests first** - Create `mini/tests/{Class}.{feature}.php`
2. **Maintain backwards compatibility** - Don't break existing APIs
3. **Follow established patterns** - Use singleton pattern, lazy initialization
4. **Update this guide** - Document new conventions and patterns

### **Error Handling Philosophy**
- **Fail gracefully** - Show meaningful errors, don't crash
- **Provide fallbacks** - Return sensible defaults when possible
- **Log for developers** - Clear diagnostics in development
- **Hide complexity from users** - Simple, clean error messages

### **Performance Considerations**
- **Static caching** - Cache expensive operations (file loads, parsing)
- **Lazy initialization** - Don't load what you don't need
- **Database efficiency** - Design for indexable queries
- **Minimal memory footprint** - Clean up temporary resources

## Integration with Applications

### **Bootstrap Pattern**
```php
// Application app/bootstrap.php (autoloaded via composer.json)
use function mini\{translator, db, fmt};

// Language detection
$language = $_GET['lang'] ?? getUserLanguagePreference() ?? detectBrowserLanguage();
translator()->trySetLanguageCode($language);

// Format values before passing to translations
function formatPrice($amount): string {
    return fmt()->currency($amount, 'USD');
}
```

### **Framework Boundaries**
- **Framework provides** - Core utilities, ICU MessageFormatter-based translation system, database abstraction
- **Application provides** - Business logic, value formatting helpers, language detection
- **Clear separation** - Framework doesn't know about application-specific concerns

This architecture enables rapid development of internationalized applications while maintaining clean, testable, and maintainable code that works exceptionally well with Claude Code's development workflow.
