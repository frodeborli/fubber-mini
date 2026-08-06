# Dispatcher Architecture

This document explains the internal architecture of Mini's dispatcher system. For developer-facing documentation, see `src/Dispatcher/README.md`.

## Overview

Dispatchers manage the complete lifecycle of requests in Mini. They are responsible for:

1. **Request creation** - Converting environment-specific input to normalized requests
2. **Service registration** - Setting up request-scoped services
3. **Request global proxies** - Installing fiber-safe $_GET, $_POST, $_COOKIE, $_SESSION
4. **Phase management** - Transitioning framework through lifecycle phases
5. **Middleware** - Running the PSR-15 middleware pipeline
6. **Request delegation** - Passing requests to handlers (Router, etc.)
7. **Exception handling** - Converting exceptions to responses
8. **Response emission** - Streaming responses back to the client

## HttpDispatcher

The HTTP dispatcher (`src/Dispatcher/HttpDispatcher.php`) is the primary dispatcher for web requests. `mini\dispatch()` is the entry-point helper that resolves it from the container and calls `dispatch()`.

### Request Lifecycle

`dispatch()` performs this sequence:

1. **Register ServerRequest service** (Transient) — the factory returns `$this->currentServerRequest`, so every `Mini::$mini->get(ServerRequestInterface::class)` call reflects the *current* request even after middleware or the Router replace it.
2. **Create the PSR-7 ServerRequest from PHP globals** (SAPI-specific).
3. **Set it as the current request** (`$this->currentServerRequest`).
4. **Install request global proxies** — `$_GET`, `$_POST`, `$_COOKIE` become `mini\Http\RequestGlobalProxy` instances; `$_SESSION` becomes a `mini\Session\SessionProxy`.
5. **Trigger the Ready phase** — service registration locks down; configuration is final.
6. **Attach the request-replacement callback** as the `mini.dispatcher.replaceRequest` attribute, so the Router can update the current request during internal reroutes without coupling to the dispatcher.
7. **Trigger `onBeforeRequest`** — a `mini\Hooks\Event` for request-scoped initialization.
8. **Build the middleware chain and handle** — registered PSR-15 middleware wraps the final handler (the Router by default) in FIFO order; each wrapper updates `currentServerRequest` as the request flows through, so `mini\request()` and the proxies always see the latest request (e.g. after a JSON body-parsing middleware).
9. **Convert exceptions** — any `\Throwable` from handling is converted via the exception converter registry; if no converter matches, it is rethrown into fatal-error handling.
10. **Emit the response**, then **trigger `onAfterRequest`** in a `finally` block (fires with request, response-or-null, exception-or-null — used for session saving, logging, cleanup).

### Step details

#### ServerRequest service (Transient)

```php
Mini::$mini->addService(
    ServerRequestInterface::class,
    \mini\Lifetime::Transient,
    fn() => $this->currentServerRequest ?? throw new \RuntimeException(
        'No ServerRequest available. ServerRequest is only available during request handling.'
    )
);
```

**Why Transient:** returns a fresh value on each `get()` call, so replacing `$currentServerRequest` (reroutes, middleware) is immediately visible everywhere without re-registering the service.

#### Creating the ServerRequest from globals

```php
// HttpDispatcher internals (SAPI-specific)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestTarget = $_SERVER['REQUEST_URI'] ?? '/';
$body = Stream::create(fopen('php://input', 'r'));
$headers = $this->extractHeadersFromServer($_SERVER);

$serverRequest = new ServerRequest(
    method: $method,
    requestTarget: $requestTarget,
    body: $body,
    headers: $headers,
    queryParams: null, // Derived from request target
    serverParams: $_SERVER,
    cookieParams: $_COOKIE,
    uploadedFiles: $uploadedFiles,
    parsedBody: $_POST,
    protocolVersion: $protocolVersion
);
```

- Uses the request target (not a reconstructed URI) as source of truth; query params derive from it.
- Headers are extracted from `$_SERVER` (`HTTP_*` keys plus `CONTENT_TYPE`/`CONTENT_LENGTH`/`CONTENT_MD5`).
- `$_FILES` is normalized to PSR-7 `UploadedFileInterface` instances, including nested multi-file structures.
- Works with PHP-FPM, mod_php, the CLI server, and CGI; future coroutine dispatchers will have their own creation logic.

#### Request global proxies

```php
private function installRequestGlobalProxies(): void
{
    static $installed = false;
    if ($installed) return;

    $_GET = new \mini\Http\RequestGlobalProxy('query');
    $_POST = new \mini\Http\RequestGlobalProxy('post');
    $_COOKIE = new \mini\Http\RequestGlobalProxy('cookie');
    $_SESSION = new \mini\Session\SessionProxy();

    $installed = true;
}
```

**Why replace globals:**
- Makes `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION` delegate to the *current* request/session instead of process-global state
- Existing read code (`$_GET['id']`) works unchanged
- Proxies reflect request replacement during reroutes and middleware
- Sets expectations early — application code is coroutine-compatible from day one

**Implementation details:**
- Proxies implement `ArrayAccess`, `Countable`, `IteratorAggregate`
- Read operations delegate to ServerRequest methods
- Write operations on `$_GET`/`$_POST`/`$_COOKIE` throw `RuntimeException` (use PSR-7 `with*()` methods)
- Empty array semantics during bootstrap (before a ServerRequest exists)
- The install is idempotent (static flag), so repeated `dispatch()` calls in tests are safe

#### Middleware

```php
// bootstrap.php — Bootstrap phase only; throws after Ready
$dispatcher = Mini::$mini->get(HttpDispatcher::class);
$dispatcher->addMiddleware(Mini::$mini->get(StaticFiles::class));
$dispatcher->addMiddleware(new CorsMiddleware());
```

Middleware executes in the order added (FIFO). Internally the dispatcher wraps the final handler in reverse order with small anonymous `RequestHandlerInterface` adapters; each adapter records the request it was handed so the Transient ServerRequest service and the global proxies always reflect the request as transformed by upstream middleware. The framework itself registers `SessionMiddleware` this way to attach session cookies to responses.

#### Request lifecycle hooks

`HttpDispatcher` exposes two typed `mini\Hooks\Event` properties:

- `$dispatcher->onBeforeRequest` — fires with the `ServerRequestInterface` before the middleware chain runs.
- `$dispatcher->onAfterRequest` — fires in a `finally` block with `(request, ?response, ?exception)`; always fires, even on failure. Use for cleanup, logging, metrics.

### Exception Handling

HttpDispatcher maintains a separate `ConverterRegistry` for exception-to-response conversion — separate from the content converter registry so exception handlers don't pollute return-value conversion.

**Exception handling flow:**

```php
try {
    $response = $handler->handle($serverRequest);
} catch (\Throwable $e) {
    $response = $this->exceptionConverters->convert($e, ResponseInterface::class);

    if ($response === null) {
        // No converter registered - rethrow for fatal error handling
        throw $e;
    }
}
```

**Converter precedence:** the most specific exception type matches first; `\Throwable` acts as catch-all. Registering a converter during the Bootstrap phase transparently *replaces* an existing converter for the same type, so applications can override the framework defaults in `src/Dispatcher/defaults.php` without errors.

**Example registration:**

```php
$dispatcher->registerExceptionConverter(
    function(NotFoundException $e): ResponseInterface {
        return new Response(render('errors/404.php'), ['Content-Type' => 'text/html'], 404);
    }
);

$dispatcher->registerExceptionConverter(
    function(\Throwable $e): ResponseInterface {
        $message = Mini::$mini->debug ? $e->getMessage() : 'Internal Server Error';
        return new Response(render('errors/500.php', compact('message')), [], 500);
    }
);
```

### Response Emission

Emission is streaming, not a single `echo`. `emitResponse()`:

1. Resolves the body size (explicit `Content-Length` header, else `StreamInterface::getSize()`).
2. Advertises `Accept-Ranges: bytes` when the body is seekable and the size is known.
3. Honors single `Range: bytes=...` requests: valid ranges become `206 Partial Content` with `Content-Range`; unsatisfiable ranges become `416`; multi-range and unparseable specs fall back to a full `200` body.
4. Sends status and headers (`header($name, false)` preserves PSR-7 multi-value header semantics, e.g. multiple `Set-Cookie`).
5. Disables `max_execution_time` and drops all output-buffering layers so bytes reach the client promptly.
6. Streams the body in 64KB chunks with `flush()` after each, stopping on EOF, satisfied range, or `connection_aborted()`.

Multi-GB downloads never materialize in memory, and a future coroutine-aware `StreamInterface` can have a writer coroutine feeding the stream while this loop reads from it.

### Fatal Error Handling

Last resort when no exception converter matched: clean all output buffers (partial output must not corrupt the error page), send a 500, and render either a detailed debug error page (exception, stack trace, source context — when `Mini::$mini->debug`) or a minimal production error page.

## Fiber Safety Model

The proxy system decouples application code from process-global request state.

`RequestGlobalProxy` (in `src/Http/RequestGlobalProxy.php`) resolves data on every access:

```php
public function offsetGet(mixed $offset): mixed
{
    $request = Mini::$mini->get(ServerRequestInterface::class); // current request
    return match($this->source) {
        'query' => $request->getQueryParams(),
        'post' => $request->getParsedBody() ?: [],
        'cookie' => $request->getCookieParams(),
    }[$offset] ?? null;
}
```

Because every `$_GET['id']` read goes through the container to whatever is *currently* the ServerRequest, the framework — not application code — owns the resolution of "current request". That is the load-bearing property for coroutine runtimes.

**Current state (SAPI mode):** one request per process, one `HttpDispatcher` singleton, `$currentServerRequest` as a plain property. No fiber concurrency happens today, and none is needed.

**Design target (coroutine mode, e.g. a phasync-based server):** "current request" resolution becomes fiber-aware — per-fiber dispatcher instances or fiber-local storage behind the same Transient service. Application code is unaffected either way: it already reads through the proxies and `mini\request()`.

The point of installing the proxies *now* is contract-setting: code written against Mini today (`$_GET['id']`, returning `ResponseInterface`, no `echo`/`header()`) moves to a coroutine runtime without a rewrite. Code that would have relied on `is_array($_GET)` or mutating superglobals fails fast today instead of breaking later.

**Trade-offs:**
- `is_array($_GET)` returns false (use `isset`/`foreach`/`count` instead)
- Slight overhead: one service lookup per access (negligible)
- Cannot mutate `$_GET`/`$_POST`/`$_COOKIE` (feature, not bug — use PSR-7 methods)

## Custom Dispatchers

Mini supports custom dispatchers for different contexts. All dispatchers should follow this pattern:

1. **Parse input** - Convert environment input to a normalized request format
2. **Register services** - Set up context-specific services
3. **Trigger Ready** - Signal end of the configuration phase
4. **Delegate handling** - Pass to the appropriate handler
5. **Handle exceptions** - Convert exceptions to appropriate responses
6. **Emit response** - Send the response back to the client

### CLI Dispatcher Example

```php
use mini\Mini;

class CliDispatcher
{
    public function dispatch(array $argv): void
    {
        // 1. Parse arguments
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        // 2. Register CLI-specific services (Bootstrap phase)
        Mini::$mini->addService(
            CliArguments::class,
            \mini\Lifetime::Scoped,
            fn() => new CliArguments($argv)
        );

        // 3. Trigger Ready phase
        Mini::$mini->phase->trigger(\mini\Phase::Ready);

        // 4. Execute command
        $handler = Mini::$mini->get(CommandHandlerInterface::class);
        exit($handler->handle($command, $args));
    }
}
```

### Phase Management

Dispatchers own phase transitions:

```php
// Bootstrap phase (default)
require 'vendor/autoload.php';
// Services can be registered

// Dispatcher triggers Ready
Mini::$mini->phase->trigger(\mini\Phase::Ready);
// No more service registration allowed

// Request handling
$response = $handler->handle($request);
```

**Why dispatchers trigger Ready:** they own the request lifecycle, they know when configuration is complete, and the transition marks the boundary between bootstrap and runtime.

## Testing Dispatchers

Key test scenarios:

1. **Service registration** - ServerRequest available during request handling
2. **Proxy installation** - $_GET, $_POST, $_COOKIE, $_SESSION work correctly
3. **Phase transitions** - Ready phase triggered at the correct time
4. **Middleware order** - FIFO execution; request replacement visible downstream
5. **Exception handling** - Exceptions convert to responses; Bootstrap-phase replacement of defaults works
6. **Response emission** - Status, headers, body; Range requests (206/416); streaming of unsized bodies
7. **Fatal errors** - fallback error page when no converter matches
8. **Hooks** - onBeforeRequest fires before handling; onAfterRequest always fires

## See Also

- `src/Dispatcher/README.md` - Developer-facing dispatcher documentation
- `src/Router/README.md` - Router usage documentation (route contract, reroutes, redirects)
- `src/Http/RequestGlobalProxy.php` - Proxy implementation
- `src/Session/README.md` - Session proxy and middleware
