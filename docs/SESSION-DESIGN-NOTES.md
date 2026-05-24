# Mini Session System - Design Notes

## Goal

Create a session system for Mini that:
1. Replaces `$_SESSION` with a proxy object (like `$_GET`, `$_POST`, `$_COOKIE`)
2. Integrates with PHP's native session handlers (`SessionHandler` class)
3. Works with fiber-based async runtimes (phasync, Swoole, ReactPHP)
4. Maintains correct `session_id()` and `session_status()` per request context

## Research Findings

### PHP Session Basics in CLI

```php
// Required settings for CLI usage:
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
```

### `$_SESSION` as Superglobal

Confirmed: Replacing `$_SESSION` with a custom object preserves superglobal behavior:
- Accessible in functions without `global $_SESSION`
- Works in closures, instance methods, static methods
- Same pattern Mini uses for `$_GET`, `$_POST`, `$_COOKIE` proxies

### PHP's `SessionHandler` Class

PHP provides `SessionHandler` - a special class that wraps the native session handler:
- Can extend it to intercept read/write (e.g., for encryption)
- Works with any configured handler (files, memcached, redis)
- The native handler is implemented in C, not accessible as an object directly

```php
class EncryptedSessionHandler extends SessionHandler {
    public function read(string $id): string|false {
        $data = parent::read($id);
        return decrypt($data);
    }

    public function write(string $id, string $data): bool {
        return parent::write($id, encrypt($data));
    }
}
```

### Session Switching Limitation

**PHP only allows ONE active session at a time.**

```php
session_id('session-A');
session_start();  // OK

session_id('session-B');
session_start();  // ERROR: Session already active
```

Must call `session_write_close()` before switching.

### Fiber-Safe Session Pattern

For concurrent fibers, use load-into-memory pattern:

```php
class FiberSession {
    private string $id;
    private array $data = [];

    public function start(): void {
        // Quick: load from storage into memory
        session_id($this->id);
        session_start();
        $this->data = $_SESSION;
        session_write_close();  // Release lock immediately
    }

    public function save(): void {
        // Quick: write from memory to storage
        session_id($this->id);
        session_start();
        $_SESSION = $this->data;
        session_write_close();
    }
}
```

### Active Context Switching for Native Integration

To make `session_id()` and `session_status()` return correct values per fiber:

```php
class SessionContext {
    private static array $contexts = [];
    private static ?string $activeId = null;

    public static function activate(string $sessionId): void {
        if ($sessionId === self::$activeId) return;

        self::suspend();  // Close previous

        session_id($sessionId);
        session_start();
        // Restore data from memory cache...

        self::$activeId = $sessionId;
    }

    public static function suspend(): void {
        if (self::$activeId === null) return;

        // Capture $_SESSION to memory cache...
        session_write_close();
        self::$activeId = null;
    }
}
```

## Mini Integration

### Using `Lifetime::Scoped`

Mini's DI container with `Lifetime::Scoped` automatically provides per-request instances:

```php
Mini::$mini->addService(
    SessionManager::class,
    Lifetime::Scoped,
    fn() => new SessionManager(
        Mini::$mini->get(ServerRequestInterface::class)
    )
);
```

- Uses `WeakMap` keyed by `getRequestScope()`
- Returns `Fiber` in fiber context, `Mini` instance in PHP-FPM
- Automatic cleanup when request/fiber ends

### SessionProxy Design

```php
class SessionProxy implements ArrayAccess {
    public function offsetGet(mixed $key): mixed {
        return Mini::$mini->get(SessionManager::class)->get($key);
    }

    public function offsetSet(mixed $key, mixed $value): void {
        Mini::$mini->get(SessionManager::class)->set($key, $value);
    }
    // ...
}

// Install globally
$_SESSION = new SessionProxy();
```

## phasync Integration

### Current State

phasync has:
- `phasync::onEnter()` / `phasync::onExit()` - called once when entering/exiting entire event loop
- `ContextInterface` - represents a request scope, inherited by child coroutines
- `ContextInterface` implements `ArrayAccess` - can store arbitrary data like `$context['session']`

phasync does NOT have:
- Per-fiber context switch callbacks
- Hooks when switching between different `ContextInterface` instances

### Context vs Fiber

Multiple fibers can share the same `ContextInterface`:

```
Context A (HTTP Request 1)          Context B (HTTP Request 2)
├── Fiber 1 (main handler)          ├── Fiber 4 (main handler)
├── Fiber 2 (async DB query)        └── Fiber 5 (async API call)
└── Fiber 3 (async file read)

Switching Fiber 1↔2↔3 = NO session switch (same context)
Switching Fiber 3→4 = YES session switch (different context)
```

Session should be tied to `ContextInterface`, not individual `Fiber`.

### ServiceContext

phasync has a special `ServiceContext` for background services:
- Single shared instance for all service coroutines
- Services don't have HTTP sessions
- Context switch callbacks must handle this case

### Where Context Switching Happens

In `StreamSelectDriver::tick()` at the `again:` label (lines 380-384):

```php
again:

try {
    $this->currentFiber   = $fiber;
    $this->currentContext = $contexts[$fiber];
    // <-- context switch detection would go here
    $value = $fiber->resume();
```

Also in `create()` when starting new fibers.

## Approaches Considered

### 1. Add `onContextSwitch` to phasync

```php
// In DriverInterface
public function onContextSwitch(Closure $callback): void;

// Usage
phasync::getDriver()->onContextSwitch(function($prev, $next) {
    if ($prev instanceof ServiceContext || $next instanceof ServiceContext) {
        return;  // Services don't have sessions
    }
    $prev?['mini.session']?->suspend();
    $next?['mini.session']?->activate();
});
```

**Pros:** Clean API, driver handles detection
**Cons:** Requires DriverInterface change, must work with all drivers (Swoole, etc.)

### 2. Fiber Wrapper Approach (Preferred)

Wrap fibers in a middleware fiber that handles context setup/teardown:

```php
function fiber_wrapper(Closure $fn, array $args, object $context) {
    $fiber = null;
    $value = null;
    while (true) {
        setup_context($context);

        if ($fiber === null) {
            $fiber = new Fiber($fn);
            $value = $fiber->start(...$args);
        } else {
            $value = $fiber->resume($value);
        }

        teardown_context($context);

        if ($fiber->isTerminated()) {
            break;
        }
        $value = Fiber::suspend($value);
    }
    return $fiber->getReturn();
}
```

**Pros:**
- No changes to phasync core
- Works with any driver
- Cross-compatible with Swoole, ReactPHP, etc.

**Cons:**
- Every fiber gets wrapped (overhead?)
- Need to integrate with phasync's fiber creation

### 3. Smart Context Switching

Only switch when context actually changes:

```php
private static ?ContextInterface $activeContext = null;

public static function ensureContext(ContextInterface $context): void {
    if ($context === self::$activeContext) {
        return;  // No switch needed
    }

    self::teardown(self::$activeContext);
    self::setup($context);
    self::$activeContext = $context;
}
```

This optimization applies to both approaches above.

## Open Questions

1. **Fiber wrapper integration:** How to make phasync use wrapped fibers? Middleware pattern?

2. **Swoole compatibility:** How does Swoole manage coroutine contexts? Does it have similar concepts?

3. **Performance:** Is the overhead of context switch callbacks acceptable? How often do real context switches happen?

4. **ServiceContext handling:** Should session system explicitly check for ServiceContext, or should phasync filter these?

5. **Session regeneration:** How to handle `session_regenerate_id()` mid-request?

6. **Cookie handling:** PSR-7 integration for reading session ID from request and setting cookie on response.

## Proposed phasync Enhancement

### The Problem

Currently phasync throws if an unknown fiber (not created by phasync) tries to use event loop functions. This prevents custom fiber wrapping.

### The Solution

Two changes to phasync:

**1. Auto-associate unknown fibers with current context**

In `DriverInterface::getContext()`, instead of throwing for unknown fibers, automatically associate them with the currently active context on first encounter.

**2. Add `phasync::setFiberCreateFunction()`**

```php
phasync::setFiberCreateFunction(function(Closure $callback): Fiber {
    // Create a wrapper fiber that handles context setup/teardown
    return new Fiber(function() use ($callback) {
        $context = phasync::getContext();
        $innerFiber = new Fiber($callback);
        $value = null;

        while (true) {
            // SETUP: activate context (session, etc.)
            $context['mini.session']?->activate();

            if (!$innerFiber->isStarted()) {
                $value = $innerFiber->start();
            } elseif ($innerFiber->isSuspended()) {
                $value = $innerFiber->resume($value);
            }

            // TEARDOWN: suspend context (session, etc.)
            $context['mini.session']?->suspend();

            if ($innerFiber->isTerminated()) {
                return $innerFiber->getReturn();
            }

            // Forward suspension to event loop
            $value = Fiber::suspend($value);
        }
    });
});
```

**Why this works:**

1. phasync calls the custom create function instead of `new Fiber()`
2. The wrapper fiber is what phasync manages
3. The inner fiber is invisible to phasync (but auto-associated if it calls phasync functions)
4. Every time the inner fiber suspends, control returns to the wrapper
5. Wrapper does teardown, suspends itself to the event loop
6. When resumed, wrapper does setup, resumes inner fiber
7. Context switching happens transparently - phasync core unchanged

**Benefits:**

- No changes to DriverInterface
- No context switch detection needed in drivers
- Works with any driver (StreamSelect, Swoole, etc.)
- Middleware-like pattern for cross-cutting concerns
- Multiple create function wrappers could be composed

## Next Steps

1. **Modify phasync**: Auto-associate unknown fibers in `getContext()`
2. **Add `setFiberCreateFunction()`**: Hook for custom fiber creation
3. **Prototype wrapper**: Test the pattern works correctly
4. **Consider Swoole**: Verify approach works with Swoole's coroutines
5. **Implement PHP-FPM-only version first**: Simpler, no fiber concerns

## File Structure (Proposed)

```
src/Session/
├── SessionInterface.php           # Service interface
├── Session.php                    # Implementation
├── SessionProxy.php               # $_SESSION replacement
├── SessionMiddleware.php          # PSR-15 for cookie handling
└── Handler/
    ├── EncryptedHandler.php       # Extends SessionHandler
    └── DatabaseHandler.php        # Implements SessionHandlerInterface
```

## References

- phasync source: `vendor/phasync/phasync/`
- Mini's existing proxy: `src/Http/RequestGlobalProxy.php`
- PHP SessionHandler: https://www.php.net/manual/en/class.sessionhandler.php
- PHP SessionHandlerInterface: https://www.php.net/manual/en/class.sessionhandlerinterface.php
