# Breaking Changes Log

Mini framework is in active internal development. We prioritize clean, simple code over backward compatibility. When we find a better approach, we remove the old implementation rather than maintain redundant code.

This log tracks breaking changes for reference when reviewing old code or conversations.

## PSR-7 Improvements: HTTP Protocol Alignment + Simplifications (2025-01-12)

Multiple PSR-7 improvements: Request/ServerRequest now use request targets (HTTP protocol alignment), PSR-17 factories removed (unnecessary abstraction), and Stream simplified (no serialization).

### What Changed
- **Request constructor**: `new Request($method, $uri, ...)` → `new Request($method, $requestTarget, ...)`
- **ServerRequest constructor**: `new ServerRequest($method, $uri, ..., $queryParams, ...)` → `new ServerRequest($method, $requestTarget, ..., $queryParams=null, ...)`
- **URI construction**: `getUri()` now constructs URI dynamically from request target + headers (unless overridden via `withUri()`)
- **Query params**: `getQueryParams()` now derives from request target by default (unless overridden via `withQueryParams()`)
- **New method**: `getQuery()` returns query string portion of request target
- **HTTPS detection**: ServerRequest detects scheme from `serverParams['HTTPS']` when constructing URI
- **Removed PSR-17**: Deleted `Psr17Factory` and `ServerRequestCreator` - unnecessary abstractions
- **HttpDispatcher**: Now creates ServerRequest directly (SAPI-specific logic belongs in dispatcher)
- **New factory**: `Request::create($method, $uri)` - convenience factory for creating outgoing requests from URIs
- **Stream::cast() simplified**: Removed `$contentType` parameter and all serialization logic - Stream is purely about wrapping stream resources
- **Removed helpers**: Deleted `create_response()`, `create_json_response()`, `emit_response()` - just use `new Response()` directly

### Core Principle
HTTP requests have **method**, **request-target**, **protocol-version**, and **headers** - not URIs. URIs are constructed on-demand from these components.

### Behavior Changes

**Request target is source of truth**:
```php
// Request target stored directly
$request = new ServerRequest('GET', '/path?foo=bar', '', [], null, []);
$request->getRequestTarget();  // '/path?foo=bar'
$request->getQuery();           // 'foo=bar'
$request->getQueryParams();     // ['foo' => 'bar'] (derived)
$request->getUri()->getQuery(); // 'foo=bar' (constructed)
```

**withQueryParams() does NOT change URI** (per PSR-7 spec):
```php
$r2 = $request->withQueryParams(['baz' => 'qux']);
$r2->getRequestTarget();        // '/path?foo=bar' (unchanged!)
$r2->getQueryParams();          // ['baz' => 'qux'] (override)
$r2->getUri()->getQuery();      // 'foo=bar' (unchanged!)
```

**withUri() and withRequestTarget() are independent**:
```php
$r3 = $request->withUri(new Uri('http://example.com/other?x=y'));
$r3->getRequestTarget();        // '/path?foo=bar' (unchanged!)
$r3->getUri()->getQuery();      // 'x=y' (URI override)
$r3->getQueryParams();          // ['foo' => 'bar'] (from request target!)
```

**Relative URI when no Host header**:
```php
$request = new Request('GET', '/path?query', '', []);
$request->getUri();  // Returns relative URI: '/path?query'
```

**HTTPS detection from server params**:
```php
$request = new ServerRequest(
    'GET', '/secure', '',
    ['Host' => 'example.com'],
    null,
    ['HTTPS' => 'on']
);
$request->getUri();  // 'https://example.com/secure'
```

### Migration

**Most applications**: No changes needed - HttpDispatcher handles request creation internally.

**Creating outgoing HTTP requests** (HTTP clients, testing):
```php
// Before
$request = new Request('GET', 'http://example.com/path?foo=bar', '');

// After - Use convenience factory
$request = Request::create('GET', 'http://example.com/path?foo=bar');

// Or direct constructor with request target
$request = new Request('GET', '/path?foo=bar', '', ['Host' => 'example.com']);
```

**Creating responses** (simple and direct):
```php
// Before
\mini\Http\create_response(200, 'Hello');
\mini\Http\create_json_response(['data' => 'value']);

// After
new Response('Hello', [], 200);
new Response(json_encode(['data' => 'value']), ['Content-Type' => 'application/json'], 200);
```

### Why These Changes?

1. **HTTP protocol correctness**: Requests ARE request targets, not URIs
2. **PSR-7 compliance**: `withQueryParams()` must not affect URI (was incorrectly coupled before)
3. **Cleaner separation**: URI, query params, and request target have distinct lifecycles
4. **Performance**: No need to construct/store URI object during request creation
5. **No PSR-17 needed**: Mini doesn't need factory abstractions - dispatchers create requests directly
6. **Environment-specific**: HttpDispatcher owns SAPI logic; future FiberHttpDispatcher will own its own creation logic
7. **Stream responsibility**: Stream wraps stream resources - serialization belongs in converters/helpers

## Native PSR-7 Implementation (Replaced Nyholm)

Mini now includes its own PSR-7 HTTP message implementation, removing the dependency on `nyholm/psr7` and `nyholm/psr7-server`.

### What Changed
- **Removed dependencies**: `nyholm/psr7` and `nyholm/psr7-server` no longer required
- **New classes**: All PSR-7 classes now in `mini\Http\Message\` namespace
- **API compatible**: Drop-in replacement, no code changes needed for standard PSR-7 usage
- **Response constructor signature**: Mini's `Response` uses `($body, $headers, $statusCode, $reasonPhrase, $protocolVersion)` instead of Nyholm's `($statusCode, $headers, $body)`

### New Classes
All classes implement their respective PSR-7 interfaces:
- `mini\Http\Message\Request`
- `mini\Http\Message\Response`
- `mini\Http\Message\ServerRequest`
- `mini\Http\Message\Stream`
- `mini\Http\Message\Uri`
- `mini\Http\Message\UploadedFile`
- `mini\Http\Message\Psr17Factory` (PSR-17 factory)
- `mini\Http\Message\ServerRequestCreator` (creates ServerRequest from globals)

### Migration

**Most applications**: No changes needed - Mini's default converters and HttpDispatcher already updated.

**If you used Nyholm classes directly**:
```php
// Before
use Nyholm\Psr7\Response;
$response = new Response(200, ['Content-Type' => 'text/html'], $body);

// After
use mini\Http\Message\Response;
$response = new Response($body, ['Content-Type' => 'text/html'], 200);
```

**Factory usage** (rare - most apps use helper functions):
```php
// Before
use Nyholm\Psr7\Factory\Psr17Factory;

// After
use mini\Http\Message\Psr17Factory;
```

### Why This Change?

1. **Zero dependencies**: Aligns with Mini's zero-dependency architecture
2. **Extendable**: Nyholm's implementation prohibited extending classes
3. **Control**: Full control over PSR-7 behavior and fixes
4. **Correctness**: Nyholm had implementation issues we needed to work around

## PSR-7 url() Function with CDN Support

The `url()` function now returns `UriInterface` instead of string and includes proper relative path resolution and CDN support.

### Changed Signature
```php
// Before
function url($path = '', array $query = []): string

// After
function url(string|UriInterface $path = '', array $query = [], bool $cdn = false): UriInterface
```

### New Behavior
- Returns `UriInterface` (PSR-7) instead of string
- Properly resolves relative paths (`.`, `..`)
- Strips scheme/host from input URLs - always resolves against base URL
- Supports CDN via `$cdn` parameter
- UriInterface is stringable - templates still work: `<?= url('/path') ?>`

### New Environment Variable
- `MINI_CDN_URL` - CDN base URL for static assets (optional, defaults to `baseUrl`)

### Migration

**Templates** - No changes needed (UriInterface is stringable):
```php
<a href="<?= url('/users') ?>">Users</a>
```

**Type hints** - Update if you type-hinted the return value:
```php
// Before
$url = url('/path');  // string

// After
$url = url('/path');  // UriInterface (but still works as string)
```

**CDN usage**:
```php
// Static assets via CDN
<link href="<?= url('/css/app.css', cdn: true) ?>" rel="stylesheet">
<img src="<?= url('/images/logo.png', cdn: true) ?>" alt="Logo">
```

## Phase System Introduction

The phase system replaces individual lifecycle hooks with a comprehensive state machine.

### Removed Methods
- `Mini::enterBootstrapPhase()` - use `Mini::$mini->phase->trigger(Phase::Bootstrap)`
- `Mini::enterReadyPhase()` - use `Mini::$mini->phase->trigger(Phase::Ready)`
- `Mini::enterFailedPhase()` - use `Mini::$mini->phase->trigger(Phase::Failed)`
- `Mini::enterShutdownPhase()` - use `Mini::$mini->phase->trigger(Phase::Shutdown)`
- `Mini::getCurrentPhase()` - use `Mini::$mini->phase->getCurrentState()`
- `Mini::enterRequestContext()` - framework now uses phase transitions
- `Mini::exitRequestContext()` - framework now uses phase transitions

### Removed Hooks
- `Mini::$onRequestReceived` - use `Mini::$mini->phase->onEnteringState(Phase::Ready, fn() => ...)`
- `Mini::$onAfterBootstrap` - use `Mini::$mini->phase->onEnteredState(Phase::Ready, fn() => ...)`

### Migration Examples

**Before:**
```php
Mini::$mini->onRequestReceived->listen(function() {
    // Authentication logic
});

Mini::$mini->onAfterBootstrap->listen(function() {
    // Output buffering setup
});
```

**After:**
```php
// Fires when entering Ready phase (before phase change completes)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Authentication logic
});

// Fires after Ready phase entered (after phase change completes)
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Output buffering setup
});
```
