# Dispatcher Architecture

This document explains the internal architecture of Mini's dispatcher system. For developer-facing documentation, see `src/Dispatcher/README.md`.

## Overview

Dispatchers manage the complete lifecycle of requests in Mini. They are responsible for:

1. **Request creation** - Converting environment-specific input to normalized requests
2. **Service registration** - Setting up request-scoped services
3. **Request global proxies** - Installing fiber-safe $_GET, $_POST, $_COOKIE
4. **Phase management** - Transitioning framework through lifecycle phases
5. **Request delegation** - Passing requests to handlers (Router, etc.)
6. **Exception handling** - Converting exceptions to responses
7. **Response emission** - Sending responses back to the client

## HttpDispatcher

The HTTP dispatcher (`src/Dispatcher/HttpDispatcher.php`) is the primary dispatcher for web requests.

### Request Lifecycle Phases

HttpDispatcher follows a strict 6-step sequence:

#### 1. Register ServerRequest Service (Transient)

```php
Mini::$mini->addService(
    ServerRequestInterface::class,
    \mini\Lifetime::Transient,
    fn() => $this->currentServerRequest ?? throw new \RuntimeException(
        'No ServerRequest available. ServerRequest is only available during request handling.'
    )
);
```

**Why Transient lifecycle:**
- Allows registration during Ready phase
- Returns fresh value on each `Mini::$mini->get()` call
- Avoids "service already registered" errors
- Bypasses phase-based registration restrictions

**Why callback returns property:**
- `$currentServerRequest` is updated during redirects/reroutes
- Service always returns the current request
- No need to re-register service after updates

#### 2. Create PSR-7 ServerRequest from Globals

HttpDispatcher creates the ServerRequest directly from PHP superglobals:

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

**Request creation behavior:**
- Reads from `$_SERVER`, `$_POST`, `$_COOKIE`, `$_FILES`
- Uses request target (not URI) as source of truth
- Query params derived from request target
- Headers extracted from `$_SERVER` (HTTP_* keys)
- HTTPS detected from `$_SERVER['HTTPS']`
- Handles uploaded files according to PSR-7 spec

**SAPI compatibility:**
- Works with PHP-FPM, mod_php, CLI server, CGI
- Also works with Swoole, ReactPHP, phasync when globals are populated

#### 3. Set Current Request

```php
$this->currentServerRequest = $serverRequest;
```

**Purpose:**
- Store reference for Transient service callback
- Allow updates during Router redirects/reroutes
- Single source of truth for current request state

#### 4. Install Request Global Proxies

```php
$this->installRequestGlobalProxies();
```

```php
private function installRequestGlobalProxies(): void
{
    static $installed = false;
    if ($installed) return;

    $_GET = new \mini\Http\RequestGlobalProxy('query');
    $_POST = new \mini\Http\RequestGlobalProxy('post');
    $_COOKIE = new \mini\Http\RequestGlobalProxy('cookie');

    $installed = true;
}
```

**Why replace globals:**
- Makes `$_GET`, `$_POST`, `$_COOKIE` fiber-safe by default
- Existing code (`$_GET['id']`) works unchanged
- Proxies delegate to current ServerRequest (updated during redirects)
- Sets expectations early - users write fiber-compatible code from day one

**Why static flag:**
- Proxies only need to be installed once
- Safe to call multiple times (idempotent)
- Survives across multiple dispatch() calls (testing scenarios)

**Implementation details:**
- Proxies implement `ArrayAccess`, `Countable`, `IteratorAggregate`
- Read operations delegate to ServerRequest methods
- Write operations throw `RuntimeException` (use PSR-7 withX() methods)
- Empty array returned during bootstrap (before ServerRequest exists)

#### 5. Trigger Ready Phase

```php
Mini::$mini->phase->trigger(\mini\Phase::Ready);
```

**Ready phase meaning:**
- Service registration locked down
- No more services can be added
- Configuration is finalized
- Ready to handle requests

**Why HttpDispatcher triggers Ready:**
- Dispatcher owns request lifecycle
- Ready phase marks transition from bootstrap to request handling
- Ensures all bootstrap services registered before request processing

#### 6. Add Request Replacement Callback

```php
$serverRequest = $serverRequest->withAttribute(
    'mini.dispatcher.replaceRequest',
    function(ServerRequestInterface $newRequest) {
        $this->currentServerRequest = $newRequest;
    }
);
```

**Purpose:**
- Allows Router to update current request during redirects/reroutes
- Router calls this callback with updated ServerRequest
- HttpDispatcher updates `$currentServerRequest` property
- Transient service immediately returns new request
- Proxies (`$_GET`, etc.) automatically reflect new values

**Why use callback:**
- Avoids coupling Router to HttpDispatcher
- Request carries its own update mechanism
- Fiber-safe (callback closes over correct dispatcher instance)
- PSR-7 compatible (stored as request attribute)

### Exception Handling

HttpDispatcher maintains a separate ConverterRegistry for exception-to-response conversion:

```php
public function __construct()
{
    $this->exceptionConverters = new \mini\Converter\ConverterRegistry();
}
```

**Why separate registry:**
- Keeps exception handling separate from content conversion
- Main converter registry handles return value → Response
- Exception converter registry handles Exception → Response
- Clear separation of concerns

**Exception handling flow:**

```php
try {
    $handler = Mini::$mini->get(RequestHandlerInterface::class);
    $response = $handler->handle($serverRequest);

} catch (ResponseAlreadySentException $e) {
    // Classical PHP (echo/header) already sent response
    return;

} catch (\Throwable $e) {
    // Try to convert exception to response
    $response = $this->exceptionConverters->convert($e, ResponseInterface::class);

    if ($response === null) {
        // No converter registered - rethrow for fatal error handling
        throw $e;
    }
}
```

**Converter precedence:**
- Most specific exception type matched first
- Falls back to broader types
- `\Throwable` acts as catch-all

**Example registration:**

```php
$dispatcher->registerExceptionConverter(
    function(NotFoundException $e): ResponseInterface {
        return new Response(404, ['Content-Type' => 'text/html'], render('404'));
    }
);

$dispatcher->registerExceptionConverter(
    function(ValidationException $e): ResponseInterface {
        $json = json_encode(['errors' => $e->errors]);
        return new Response(400, ['Content-Type' => 'application/json'], $json);
    }
);

$dispatcher->registerExceptionConverter(
    function(\Throwable $e): ResponseInterface {
        $statusCode = 500;
        $message = Mini::$mini->debug ? $e->getMessage() : 'Internal Server Error';
        return new Response($statusCode, [], render('error', compact('message')));
    }
);
```

### Response Emission

```php
private function emitResponse(ResponseInterface $response): void
{
    // Send status code
    http_response_code($response->getStatusCode());

    // Send headers
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }

    // Send body
    echo $response->getBody();
}
```

**Header handling:**
- `header($name, false)` allows multiple headers with same name
- Follows PSR-7 multi-value header semantics
- Example: Multiple `Set-Cookie` headers

**Body streaming:**
- PSR-7 body is `StreamInterface`
- `__toString()` reads entire stream
- For large responses, could implement chunked streaming

### Fatal Error Handling

Last resort when no exception converter matched:

```php
private function handleFatalError(\Throwable $e): void
{
    // Clean output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $statusCode = 500;
    $message = Mini::$mini->debug
        ? htmlspecialchars($e->getMessage())
        : 'Internal Server Error';

    http_response_code($statusCode);
    echo "<!DOCTYPE html>...";  // Basic error page
}
```

**Why clean output buffer:**
- Previous handlers may have started buffering
- Partial output could corrupt error page
- Ensures clean slate for error display

## Request Global Proxies

The proxy system makes Mini fiber-safe by default.

### RequestGlobalProxy Class

Located in `src/Http/RequestGlobalProxy.php`:

```php
class RequestGlobalProxy implements \ArrayAccess, \Countable, \IteratorAggregate
{
    public function __construct(
        private readonly string $source  // 'query', 'post', 'cookie'
    ) {}

    private function getData(): array
    {
        try {
            $request = Mini::$mini->get(ServerRequestInterface::class);
        } catch (\Throwable $e) {
            // No request available yet during bootstrap
            return [];
        }

        return match($this->source) {
            'query' => $request->getQueryParams(),
            'post' => $request->getParsedBody() ?: [],
            'cookie' => $request->getCookieParams(),
            default => throw new \RuntimeException("Invalid source: {$this->source}"),
        };
    }

    public function offsetGet(mixed $offset): mixed
    {
        $data = $this->getData();
        return $data[$offset] ?? null;
    }

    // ... other ArrayAccess methods
}
```

### Fiber Safety Mechanism

**Traditional PHP (not fiber-safe):**
```php
// Static/global state
static $currentRequest = null;

function handle($request) {
    global $currentRequest;
    $currentRequest = $request;  // ⚠️ Overwrites across fibers

    Fiber::suspend();  // Fiber 1 suspends
    // Fiber 2 runs: $currentRequest = $request2
    // Fiber 1 resumes: $currentRequest is now wrong!
}
```

**Mini approach (fiber-safe):**
```php
// Proxy delegates to service container
$_GET = new RequestGlobalProxy('query');

// Dispatcher stores current request
$this->currentServerRequest = $request1;

// Fiber 1: $_GET['id'] → getData() → Mini::$mini->get(ServerRequest) → $request1
Fiber::suspend();

// Fiber 2 starts
$this->currentServerRequest = $request2;
// Fiber 2: $_GET['id'] → getData() → Mini::$mini->get(ServerRequest) → $request2

// Fiber 1 resumes
// Fiber 1: $_GET['id'] → getData() → Mini::$mini->get(ServerRequest) → $request1
```

**Why this works:**
- Each dispatcher instance has its own `$currentServerRequest`
- Transient service factory captures `$this` (dispatcher instance)
- Fibers share code but not local variables
- Each fiber gets its own dispatcher instance (via service container)

**Wait, but HttpDispatcher is Singleton!**

Good catch. Here's the actual mechanism:

```php
// HttpDispatcher is Singleton, BUT...
// When using Fibers with async frameworks:

// Fiber 1 starts
$dispatcher->dispatch();  // Sets $this->currentServerRequest = $request1

// Fiber 1 suspends during request handling
Fiber::suspend();

// Fiber 2 starts
$dispatcher2 = new HttpDispatcher();  // Different instance!
$dispatcher2->dispatch();  // Sets $dispatcher2->currentServerRequest = $request2
```

**Actually, for true fiber concurrency, we'd need:**
- Fiber-local storage for current request
- OR separate dispatcher instances per fiber
- OR request context passed explicitly

**Current implementation (SAPI mode):**
- Single request per process
- No fiber concurrency yet
- Proxies prepare for future fiber support
- Sets expectations: users write fiber-compatible code now

**Future implementation (async mode):**
- Multiple HttpDispatcher instances (one per fiber)
- Each fiber gets its own dispatcher from container
- OR: Store current request in Fiber-local storage
- Proxies work unchanged

### Why Install Proxies Now

User quote:
> "if users start using Mini - and somehow they do: if(is_array($_GET)) or whatever - then this code works today, but will fail to work when they update Mini at some point. Better to set that constraint and expectation today, even if we're not implementing it fully."

**Benefits:**
1. **Forward compatibility** - Code written today works with fibers tomorrow
2. **Clear constraints** - Users learn proxies early, not as breaking change
3. **Migration path** - No rewrite needed when enabling fiber concurrency
4. **Consistent behavior** - Same semantics across SAPI and async modes

**Trade-offs:**
- `is_array($_GET)` returns false (acceptable - use isset/foreach/count instead)
- Slight performance overhead (negligible - one service lookup per access)
- Cannot modify globals (feature, not bug - use PSR-7 methods)

## Custom Dispatchers

Mini supports custom dispatchers for different contexts.

### CLI Dispatcher Example

```php
namespace mini\Dispatcher;

use mini\Mini;

class CliDispatcher
{
    public function dispatch(array $argv): void
    {
        // 1. Parse arguments
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        // 2. Register CLI-specific services
        Mini::$mini->addService(
            CliArguments::class,
            \mini\Lifetime::Scoped,
            fn() => new CliArguments($argv)
        );

        // 3. Trigger Ready phase
        Mini::$mini->phase->trigger(\mini\Phase::Ready);

        // 4. Execute command
        $handler = Mini::$mini->get(CommandHandlerInterface::class);
        $exitCode = $handler->handle($command, $args);

        // 5. Exit
        exit($exitCode);
    }
}
```

### WebSocket Dispatcher Example

```php
namespace mini\Dispatcher;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketDispatcher implements MessageComponentInterface
{
    public function onMessage(ConnectionInterface $from, $msg)
    {
        // 1. Parse message to request
        $request = $this->parseMessage($msg);

        // 2. Register connection-specific services
        Mini::$mini->replaceService(
            ConnectionInterface::class,
            fn() => $from
        );

        // 3. Handle request
        $handler = Mini::$mini->get(MessageHandlerInterface::class);
        $response = $handler->handle($request);

        // 4. Send response
        $from->send(json_encode($response));
    }
}
```

## Dispatcher Responsibilities

All dispatchers should follow this pattern:

1. **Parse input** - Convert environment input to normalized request format
2. **Register services** - Set up context-specific services
3. **Trigger Ready** - Signal end of configuration phase
4. **Delegate handling** - Pass to appropriate handler
5. **Handle exceptions** - Convert exceptions to appropriate responses
6. **Emit response** - Send response back to client

### Phase Management

Dispatchers are responsible for phase transitions:

```php
// Bootstrap phase (default)
require 'vendor/autoload.php';
// Services can be registered

// Dispatcher triggers Ready
Mini::$mini->phase->trigger(\mini\Phase::Ready);
// No more service registration allowed

// Request handling
$response = $handler->handle($request);

// Shutdown hooks
Mini::$mini->phase->trigger(\mini\Phase::Shutdown);
```

**Why dispatchers trigger Ready:**
- They own the request lifecycle
- They know when configuration is complete
- They mark the transition from bootstrap to runtime

## Testing Dispatchers

Key test scenarios:

1. **Service registration** - ServerRequest available during request
2. **Proxy installation** - $_GET, $_POST, $_COOKIE work correctly
3. **Phase transitions** - Ready phase triggered at correct time
4. **Exception handling** - Exceptions convert to responses
5. **Response emission** - Status, headers, body sent correctly
6. **Fatal errors** - handleFatalError() called when no converter
7. **Request updates** - Router can update current request
8. **Concurrent requests** - Multiple fibers with separate contexts

## Future Enhancements

### Fiber-Local Storage

For true fiber concurrency:

```php
class HttpDispatcher
{
    private static \WeakMap $fiberRequests;

    public function __construct()
    {
        self::$fiberRequests = new \WeakMap();
    }

    private function setCurrentRequest(ServerRequestInterface $request): void
    {
        $fiber = \Fiber::getCurrent() ?? 'main';
        self::$fiberRequests[$fiber] = $request;
    }

    private function getCurrentRequest(): ?ServerRequestInterface
    {
        $fiber = \Fiber::getCurrent() ?? 'main';
        return self::$fiberRequests[$fiber] ?? null;
    }
}
```

### Middleware Support

Add PSR-15 middleware stack:

```php
class HttpDispatcher
{
    private array $middleware = [];

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function dispatch(): void
    {
        // Build middleware stack
        $handler = new MiddlewareStack($this->middleware, $finalHandler);
        $response = $handler->handle($serverRequest);
    }
}
```

### Request ID Tracking

Add unique request IDs for logging:

```php
public function dispatch(): void
{
    $requestId = bin2hex(random_bytes(16));
    $serverRequest = $serverRequest->withAttribute('mini.request.id', $requestId);

    // Available in logs
    logger()->info('Request started', ['request_id' => $requestId]);
}
```

## See Also

- `src/Dispatcher/README.md` - Developer-facing dispatcher documentation
- `docs/routing.md` - Routing internals
- `src/Router/README.md` - Router usage documentation
- `src/Http/RequestGlobalProxy.php` - Proxy implementation
