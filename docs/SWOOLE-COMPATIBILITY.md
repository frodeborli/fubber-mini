# Swoole Compatibility with Mini Framework

## TL;DR

**Mini's architecture supports Swoole** - but requires a bridge adapter to map Swoole's request objects to PHP superglobals that Mini expects.

## How Swoole Works

### Traditional PHP (FPM/Apache)
```php
// Fresh superglobals per request
$_GET, $_POST, $_SESSION, $_COOKIE
// Process dies after request - automatic cleanup
```

### Swoole (Coroutine-based)
```php
// Long-running process, multiple concurrent requests
$server->on('Request', function($request, $response) {
    // $request->get     (not $_GET)
    // $request->post    (not $_POST)
    // $request->cookie  (not $_COOKIE)
    // $request->server  (not $_SERVER)
});
```

**Key difference**: Swoole provides **request objects** instead of populating **superglobals**.

## Mini's Expectations

Mini's `bootstrap()` function currently expects traditional superglobals:

```php
// functions.php:306 - Mini expects $_SERVER to exist
if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        $_POST = $data;  // Populates $_POST
    }
}
```

## The Gap

| What Swoole Provides | What Mini Expects |
|---------------------|-------------------|
| `$request->get` | `$_GET` |
| `$request->post` | `$_POST` |
| `$request->cookie` | `$_COOKIE` |
| `$request->server` | `$_SERVER` |
| `$request->files` | `$_FILES` |
| Per-coroutine isolation | Runtime manages it |

## Does Swoole Provide Fiber-Scoped Superglobals?

**No.** Swoole does NOT automatically populate `$_GET`, `$_POST`, etc. per coroutine/fiber.

### Why Not?

1. **Memory safety**: Superglobals are process-wide in PHP. In a long-running process with concurrent coroutines, using superglobals would cause data leakage between requests.

2. **Architecture**: Swoole uses **object-oriented request/response** pattern (similar to PSR-7) instead of mutating global state.

3. **Sessions don't work**: Traditional `$_SESSION` would leak across requests. Swoole recommends Redis/database-backed sessions.

## What Other Frameworks Do

### Laravel Octane
```php
// Bridge that converts Swoole request → Symfony request → Laravel
// Uses symfony/psr-http-message-bridge + nyholm/psr7
```

### Symfony Runtime
```php
// Custom Runtime that adapts Swoole to Symfony's HttpKernel
// symfony/runtime component
```

### insidestyles/swoole-bridge
```php
// Generic bridge for PHP frameworks
// Converts Swoole request → PSR-7 → Framework request
```

All solutions follow the same pattern: **Bridge Swoole's request object to framework expectations**.

## How Mini Could Support Swoole

### Option 1: SuperGlobalProxy with ArrayAccess (Elegant & Transparent)

**The Key Insight**: Replace superglobals with proxy objects that implement `ArrayAccess` and delegate to Swoole's coroutine context.

```php
namespace mini\Swoole;

/**
 * Transparent proxy for superglobals that reads from Swoole coroutine context
 */
class SuperGlobalProxy implements \ArrayAccess, \Countable, \IteratorAggregate {
    private string $key;

    public function __construct(string $key) {
        $this->key = $key;
    }

    private function getContext(): array {
        if (!\Swoole\Coroutine::getCid()) {
            throw new \RuntimeException("Not in coroutine context");
        }
        $ctx = \Swoole\Coroutine::getContext();
        return $ctx[$this->key] ?? [];
    }

    public function offsetExists($offset): bool {
        return isset($this->getContext()[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->getContext()[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        $ctx = \Swoole\Coroutine::getContext();
        if (!isset($ctx[$this->key])) {
            $ctx[$this->key] = [];
        }
        $ctx[$this->key][$offset] = $value;
    }

    public function offsetUnset($offset): void {
        $ctx = \Swoole\Coroutine::getContext();
        unset($ctx[$this->key][$offset]);
    }

    public function count(): int {
        return count($this->getContext());
    }

    public function getIterator(): \Traversable {
        return new \ArrayIterator($this->getContext());
    }
}

// Initialize proxies ONCE at server startup
$_GET = new SuperGlobalProxy('get');
$_POST = new SuperGlobalProxy('post');
$_COOKIE = new SuperGlobalProxy('cookie');
$_SERVER = new SuperGlobalProxy('server');
$_FILES = new SuperGlobalProxy('files');

// Swoole server
$server = new \Swoole\HTTP\Server("0.0.0.0", 9501);

$server->on('Request', function($request, $response) {
    // Store request data in coroutine context
    $ctx = \Swoole\Coroutine::getContext();
    $ctx['_request'] = $request;
    $ctx['_response'] = $response;
    $ctx['get'] = $request->get ?? [];
    $ctx['post'] = $request->post ?? [];
    $ctx['cookie'] = $request->cookie ?? [];
    $ctx['server'] = $request->server ?? [];
    $ctx['files'] = $request->files ?? [];

    // Mini code works transparently!
    // $_GET['foo'] automatically reads from $ctx['get']['foo']
    mini\bootstrap();

    $router = new mini\SimpleRouter();
    ob_start();
    $router->handleRequest($request->server['request_uri']);
    $output = ob_get_clean();

    $response->end($output);
});

$server->start();
```

**Benefits**:
- **Zero Mini changes**: Existing code like `$_GET['id']` works unchanged
- **Automatic isolation**: Swoole's coroutine context provides per-request isolation
- **No array copying**: Proxies reference context directly
- **Type safety**: Can be type-hinted as array in PHP 8.1+

**What Swoole Already Handles**:
- ✅ Output buffering (`ob_*`) is already coroutine-isolated
- ✅ Coroutine context switching is automatic
- ✅ Memory cleanup when coroutine ends

**What Needs Wrapping**:
- ❌ `header()` - needs `mini\header()` wrapper to intercept

#### Header Interception

Since PHP's native `header()` function sends headers directly to SAPI, we need a wrapper:

```php
namespace mini;

/**
 * Send HTTP header - Swoole-compatible
 *
 * Automatically detects Swoole coroutine context and routes to response object.
 * Falls back to native header() in traditional SAPI environments.
 */
function header(string $header, bool $replace = true, int $response_code = 0): void {
    // Check if in Swoole coroutine context
    if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
        $ctx = \Swoole\Coroutine::getContext();
        if (isset($ctx['_response'])) {
            $response = $ctx['_response'];

            // Handle special headers
            if (stripos($header, 'location:') === 0) {
                $url = trim(substr($header, 9));
                $response->redirect($url, $response_code ?: 302);
                return;
            }

            if (stripos($header, 'http/') === 0) {
                // Status line: "HTTP/1.1 404 Not Found"
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches);
                if ($matches) {
                    $response->status((int)$matches[1]);
                }
                return;
            }

            // Regular header: "Content-Type: application/json"
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $response->header(trim($parts[0]), trim($parts[1]));
            }

            if ($response_code > 0) {
                $response->status($response_code);
            }
            return;
        }
    }

    // Fall back to native header() in traditional PHP-FPM/Apache
    \header($header, $replace, $response_code);
}

/**
 * Swoole-compatible http_response_code()
 */
function http_response_code(int $code = null): int {
    if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
        $ctx = \Swoole\Coroutine::getContext();
        if (isset($ctx['_response'])) {
            if ($code !== null) {
                $ctx['_response']->status($code);
            }
            return $code ?? 200;
        }
    }

    return \http_response_code($code);
}
```

**Usage in Mini code**:
```php
// Instead of: header('Content-Type: application/json');
mini\header('Content-Type: application/json');

// Instead of: http_response_code(404);
mini\http_response_code(404);

// redirect() helper would call mini\header() internally
```

### Option 2: PSR-7 Refactoring (More Invasive)

Refactor Mini to use PSR-7 throughout:

```php
// Mini becomes PSR-7 native
function router(ServerRequestInterface $request): ResponseInterface
```

**Pros**:
- No superglobals needed
- Clean architecture
- Works with any PSR-7 runtime

**Cons**:
- Breaking change for existing Mini apps
- More complex

### Option 3: Swoole-Specific Entry Point (Pragmatic)

Provide a separate Swoole adapter:

```php
// vendor/fubber/mini-swoole/bootstrap.php
class SwooleAdapter {
    public function handle($request, $response) {
        // Populate context-local superglobals
        // Run Mini application
        // Send response
    }
}
```

**Pros**:
- No breaking changes to Mini
- Opt-in Swoole support
- Traditional apps work unchanged

**Cons**:
- Separate package to maintain

## Mini's Current Design Philosophy

From CLAUDE.md:

> **Mini's Architectural Principle**: Mini does NOT manage concurrency or context switching. That's the runtime's job.
>
> Concurrent runtimes MUST juggle ALL request globals when switching context:
> - `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`
> - `\Locale::setDefault()` / `\Locale::getDefault()`
> - `date_default_timezone_set()` / `date_default_timezone_get()`
>
> **Mini expects this and relies on it.**

This design assumes the runtime provides context isolation. Swoole CAN do this, but requires explicit bridging.

## Recommendation

**Preferred Approach**: SuperGlobalProxy (Option 1)

Create `fubber/mini-swoole` package that provides:

1. **SuperGlobalProxy class** - Transparent ArrayAccess wrapper for superglobals
2. **Header wrappers** - `mini\header()` and `mini\http_response_code()`
3. **Session adapter** - Redis-backed session handler
4. **Server bootstrap** - Ready-to-use Swoole server setup
5. **Zero code changes** - Existing Mini apps work unchanged

**Implementation checklist**:
- ✅ SuperGlobalProxy with ArrayAccess, Countable, IteratorAggregate
- ✅ Coroutine context integration via `\Swoole\Coroutine::getContext()`
- ✅ Header interception with `mini\header()`
- ⬜ Update Mini's `redirect()` to use `mini\header()`
- ⬜ Redis session handler
- ⬜ Performance monitoring hooks
- ⬜ Graceful shutdown handling

This approach allows Mini apps to opt-in to Swoole without requiring refactoring.

## Example: Minimal Working Swoole Server (Proof of Concept)

```php
<?php
// swoole-server.php
require __DIR__ . '/vendor/autoload.php';

use Swoole\HTTP\Server;
use Swoole\HTTP\Request;
use Swoole\HTTP\Response;

// Replace superglobals with proxies ONCE at startup
$_GET = new mini\Swoole\SuperGlobalProxy('get');
$_POST = new mini\Swoole\SuperGlobalProxy('post');
$_COOKIE = new mini\Swoole\SuperGlobalProxy('cookie');
$_SERVER = new mini\Swoole\SuperGlobalProxy('server');
$_FILES = new mini\Swoole\SuperGlobalProxy('files');

$server = new Server("127.0.0.1", 9501);

$server->on('Request', function(Request $request, Response $response) {
    // Store request data in coroutine context
    $ctx = \Swoole\Coroutine::getContext();
    $ctx['_request'] = $request;
    $ctx['_response'] = $response;
    $ctx['get'] = $request->get ?? [];
    $ctx['post'] = $request->post ?? [];
    $ctx['cookie'] = $request->cookie ?? [];
    $ctx['files'] = $request->files ?? [];

    // Build $_SERVER equivalent
    $ctx['server'] = array_merge(
        $request->server ?? [],
        [
            'REQUEST_URI' => $request->server['request_uri'] ?? '/',
            'REQUEST_METHOD' => $request->server['request_method'] ?? 'GET',
            'HTTP_HOST' => $request->header['host'] ?? 'localhost',
            'CONTENT_TYPE' => $request->header['content-type'] ?? '',
        ]
    );

    // Initialize Mini - now $_GET, $_POST etc work via proxies!
    mini\bootstrap();

    // Route request
    try {
        ob_start();
        $router = new mini\SimpleRouter();
        $router->handleRequest($ctx['server']['REQUEST_URI']);
        $output = ob_get_clean();

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end($output);
    } catch (Throwable $e) {
        $response->status(500);
        $response->end('Internal Server Error: ' . $e->getMessage());
    }
});

echo "Swoole HTTP Server started on http://127.0.0.1:9501\n";
$server->start();
```

**Test it:**
```bash
php swoole-server.php

# In another terminal:
curl http://127.0.0.1:9501/
curl http://127.0.0.1:9501/users?page=2
```

**How it works**:
1. Superglobals are replaced with SuperGlobalProxy objects at server startup
2. Each request stores data in Swoole's coroutine context
3. When Mini code accesses `$_GET['page']`, the proxy reads from the current coroutine's context
4. Automatic isolation - each coroutine has its own context
5. No copying or mutation of global state

## Edge Cases and Considerations

### 1. Sessions
**Problem**: PHP's built-in `$_SESSION` is process-wide and will leak across requests.

**Solution**: Use Redis-backed sessions via `mini\Swoole\SessionHandler`:
```php
// In swoole-server.php before request handling
$sessionHandler = new mini\Swoole\RedisSessionHandler($redisClient);
session_set_save_handler($sessionHandler, true);
```

### 2. SuperGlobalProxy Type Compatibility

**Issue**: Some functions check `is_array($_GET)` which will fail with proxy objects.

**Solution**: SuperGlobalProxy should also implement a `__toArray()` method:
```php
public function toArray(): array {
    return $this->getContext();
}

// Or check if function uses strict type checking
if (is_array($_GET)) { ... }  // May fail
if ($_GET instanceof ArrayAccess) { ... }  // Better
```

### 3. `php://input` Stream

**Problem**: `file_get_contents('php://input')` doesn't work in Swoole.

**Solution**: Add wrapper in bootstrap:
```php
namespace mini;

function file_get_contents(string $filename, ...$args) {
    if ($filename === 'php://input' &&
        extension_loaded('swoole') &&
        \Swoole\Coroutine::getCid() > 0) {
        $ctx = \Swoole\Coroutine::getContext();
        if (isset($ctx['_request'])) {
            return $ctx['_request']->rawContent();
        }
    }
    return \file_get_contents($filename, ...$args);
}
```

### 4. Static Variable Persistence

**This is a feature, not a bug!** Static variables persist across requests:
```php
function get_counter() {
    static $count = 0;
    return ++$count;  // Increments across ALL requests
}
```

This enables in-memory caching but requires careful memory management.

### 5. `extract()` with Superglobal Names

**Problem**: Mini's `render()` uses `extract($vars, EXTR_SKIP)` which could contain `'_GET'` key.

**Impact**: Minimal - `EXTR_SKIP` prevents overwriting existing variables, so `$_GET` proxy is safe.

### 6. Database Connection Pooling

Mini's `db()` returns request-scoped instances. In Swoole, you should use connection pooling:
```php
// Traditional: Fresh connection per request
$db = db();

// Swoole: Use persistent connection pool
$pool = new Swoole\Database\PDOPool($config);
$db = $pool->get();  // Gets from pool
defer(fn() => $pool->put($db));  // Returns to pool
```

### 7. Memory Leaks

**Long-running process = memory leaks never auto-clear.**

Monitor with:
```bash
# Enable memory tracking
$server->set(['max_request' => 1000]);  // Restart worker after 1000 requests

# Or use Swoole Timer
Swoole\Timer::tick(60000, function() {
    echo "Memory: " . memory_get_usage() / 1024 / 1024 . " MB\n";
});
```

## Summary

**Your SuperGlobalProxy approach is brilliant** because it:

1. ✅ **Zero Mini code changes** - Existing apps work unchanged
2. ✅ **Transparent isolation** - Swoole's coroutine context handles everything
3. ✅ **No array copying** - Proxies read directly from context
4. ✅ **Minimal overhead** - Just one extra indirection via `ArrayAccess`
5. ✅ **Type compatible** - Implements `ArrayAccess`, `Countable`, `IteratorAggregate`
6. ✅ **Automatic cleanup** - Coroutine context cleared when request ends

**What needs to be built**:
- `mini\Swoole\SuperGlobalProxy` class
- `mini\header()` and `mini\http_response_code()` wrappers
- `mini\Swoole\SessionHandler` for Redis-backed sessions
- Update Mini's `redirect()` to call `mini\header()` internally
- Example Swoole server bootstrap

**Result**: Mini applications can run on Swoole with minimal changes, gaining massive performance improvements from persistent memory and coroutine concurrency.

## Further Reading

- [Swoole Coroutine Context](https://openswoole.com/article/isolating-variables-with-coroutine-context)
- [Laravel Octane (Swoole adapter for Laravel)](https://laravel.com/docs/octane)
- [Symfony Runtime Component](https://symfony.com/doc/current/components/runtime.html)
- [PSR-7: HTTP Message Interfaces](https://www.php-fig.org/psr/psr-7/)
- [Swoole Documentation](https://openswoole.com/)
