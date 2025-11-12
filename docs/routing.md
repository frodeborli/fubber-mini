# Routing Internals

This document explains the internal architecture of Mini's routing system. For developer-facing documentation, see `src/Router/README.md`.

## Architecture Overview

Mini's routing system implements PSR-15 `RequestHandlerInterface` and uses exception-based control flow for redirects and reroutes. The router is **fiber-safe** and **stateless** - all request-specific state is stored in local variables or PSR-7 request attributes.

### Components

1. **Router** (`src/Router/Router.php`) - Main PSR-15 request handler
2. **FileRouter** (`src/Router/FileRouter.php`) - File-based route resolution
3. **PatternRouter** (`src/Router/PatternRouter.php`) - Pattern matching for dynamic routes
4. **Redirect/Reroute Exceptions** - Control flow for internal routing
5. **DefaultHandlers** (`src/Router/DefaultHandlers.php`) - Extension point for custom handlers

### Request Flow

```
HttpDispatcher::dispatch()
    ↓
Router::handle(ServerRequest) → PSR-15 RequestHandler
    ↓
[Loop: handle redirects/reroutes]
    ↓
FileRouter::resolve($path)
    ↓
Found file? → require file OR execute __DEFAULT__.php
    ↓
Handle return value (string, false, array, RequestHandler, etc.)
    ↓
Convert to PSR-7 Response
```

## Router Class

The Router class is the main entry point implementing `RequestHandlerInterface::handle()`.

### Key Responsibilities

1. **Redirect loop protection** - Track redirect count (local variable)
2. **Internal reroute handling** - Catch `Reroute` exceptions
3. **Redirect handling** - Catch `Redirect` exceptions
4. **File execution** - Delegate to FileRouter
5. **Response conversion** - Use ConverterRegistry to convert results to ResponseInterface

### State Management (Fiber-Safe)

**All request-specific state is local or in request attributes:**

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    // Local variable for redirect tracking (fiber-safe)
    $redirectCount = 0;
    $maxRedirects = 10;

    while ($redirectCount < $maxRedirects) {
        try {
            // Resolve and execute route
            $result = $this->resolveAndExecute($request, $redirectCount);

            // Convert to response
            return $this->convertToResponse($result, $request);

        } catch (Redirect $e) {
            $redirectCount++;
            $request = $this->createRedirectRequest($request, $e->path);
            $this->replaceGlobalRequest($request);

        } catch (Reroute $e) {
            $redirectCount++;
            $request = $this->createRerouteRequest($request, $e->path);
            $this->replaceGlobalRequest($request);
        }
    }

    throw new \RuntimeException("Too many redirects (>$maxRedirects)");
}
```

**Why this works:**
- `$redirectCount` is a local variable - each fiber gets its own copy
- No instance properties store request-specific state
- Router can handle concurrent requests via fibers

### Underscore Path Security

Paths containing `/_` are only accessible after a redirect has occurred (when `$redirectCount > 0`). This prevents direct access to internal files:

```php
private function resolveAndExecute(
    ServerRequestInterface $request,
    int $redirectCount
): mixed {
    $path = $request->getUri()->getPath();

    // Security: Block direct access to underscore paths
    $allowUnderscore = $redirectCount > 0;
    if (!$allowUnderscore && str_contains($path, '/_')) {
        throw new NotFoundException("Path contains /_: $path");
    }

    return $this->fileRouter->resolve($path, $allowUnderscore);
}
```

**Examples:**
- Direct request to `/_routes/admin.php` → 404
- Internal redirect to `/_routes/admin.php` → Allowed
- Request to `/blog/__DEFAULT__.php` → 404 (reserved file)

## FileRouter Class

Handles file-based route resolution and execution.

### Resolution Priority

1. **Exact file match** - `_routes/users.php` for `/users`
2. **Index file** - `_routes/users/index.php` for `/users/`
3. **Trailing slash redirect** - `/users` → `/users/` if only index exists
4. **__DEFAULT__.php patterns** - Scan parent directories for dynamic routing
5. **Global fallback routes** - `config/routes.php` (if configured)
6. **404** - Throw `NotFoundException`

### __DEFAULT__.php Execution

When a __DEFAULT__.php file is found, it's executed and its return value determines behavior:

```php
private function handleDefaultFile(string $file, string $remainingPath): mixed
{
    // Execute __DEFAULT__.php
    $result = require $file;

    // Handle different return types
    if ($result === null) {
        // Response already sent (echo, header(), etc.)
        throw new ResponseAlreadySentException();
    }

    if ($result instanceof RequestHandlerInterface) {
        // PSR-15 handler (Slim, Mezzio, custom handler)
        return $result;
    }

    if (is_array($result)) {
        // Pattern routes - match against remaining path
        return $this->matchPatterns($result, $remainingPath);
    }

    // Check custom handlers via DefaultHandlers
    $handled = $this->defaultHandlers->handle($result, $remainingPath);
    if ($handled !== null) {
        return $handled;
    }

    throw new \RuntimeException(
        "__DEFAULT__.php returned unsupported type: " . get_debug_type($result)
    );
}
```

### Pattern Matching

Pattern routes use FastRoute-style syntax:

```php
// _routes/blog/__DEFAULT__.php
return [
    '/{slug}' => fn($slug) => "posts/view.php?slug=$slug",
    '/{year:\d{4}}/{month:\d{2}}' => fn($y, $m) => "archive.php?year=$y&month=$m",
];
```

**Pattern resolution:**
1. Compile patterns to regex (cached in PatternRouter)
2. Match against remaining path
3. Extract parameters from captures
4. Call handler with parameters (type-cast based on type hints)
5. Handle return value (string → Redirect, false → 404, etc.)

## Exception-Based Control Flow

Mini uses exceptions for internal routing control:

### Redirect Exception

**Purpose:** Internal redirect to a different route (changes URL in request)

```php
// _routes/blog/__DEFAULT__.php
return [
    '/{slug}' => fn($slug) => "posts/view.php?slug=$slug"
];
```

When handler returns a string, Router throws `Redirect` exception:

```php
if (is_string($result)) {
    throw new Redirect($result);
}
```

Router catches it and creates a new request:

```php
catch (Redirect $e) {
    $redirectCount++;
    $newUri = $request->getUri()->withPath($e->path)->withQuery($e->query);
    $request = $request->withUri($newUri)->withQueryParams($e->queryParams);
    $this->replaceGlobalRequest($request);
}
```

### Reroute Exception

**Purpose:** Internal reroute without changing the URL (used for trailing slash normalization)

```php
// User requests /users (no file)
// Found /users/index.php
throw new Reroute('/users/'); // Reroute to /users/ internally
```

**Difference from Redirect:**
- Redirect: Changes URL in request (affects relative links, etc.)
- Reroute: Keeps original URL, just resolves different file

## Request Global Updates

When Router creates a new request (via Redirect/Reroute), it must update HttpDispatcher's `$currentServerRequest`:

```php
private function replaceGlobalRequest(ServerRequestInterface $request): void
{
    $callback = $request->getAttribute('mini.dispatcher.replaceRequest');
    if ($callback instanceof \Closure) {
        $callback($request);
    }
}
```

This callback is provided by HttpDispatcher:

```php
// HttpDispatcher::dispatch()
$serverRequest = $serverRequest->withAttribute(
    'mini.dispatcher.replaceRequest',
    function(ServerRequestInterface $newRequest) {
        $this->currentServerRequest = $newRequest;
    }
);
```

**Why this matters:**
- `$_GET`, `$_POST`, `$_COOKIE` are proxies that delegate to `Mini::$mini->get(ServerRequestInterface::class)`
- ServerRequest service returns `HttpDispatcher->currentServerRequest`
- When Router updates the request, proxies immediately reflect new values
- No need to manually update `$_GET` array - it's automatic via proxies

## Response Conversion

Router uses ConverterRegistry to convert handler results to ResponseInterface:

```php
private function convertToResponse($result, ServerRequestInterface $request): ResponseInterface
{
    // Already a response
    if ($result instanceof ResponseInterface) {
        return $result;
    }

    // Try converter registry
    $response = $this->converters->convert($result, ResponseInterface::class);
    if ($response !== null) {
        return $response;
    }

    throw new \RuntimeException(
        "Could not convert result to ResponseInterface: " . get_debug_type($result)
    );
}
```

**Built-in converters:**
- `string` → Response with text/html content
- `array` → Response with application/json content
- Custom converters can be registered

## DefaultHandlers Extension Point

DefaultHandlers provides a hook for handling custom __DEFAULT__.php return values:

```php
// config/mini/Router/Router.php
$router = new Router();

$router->defaultHandlers->listen(function($result, $remainingPath) {
    if ($result instanceof MyCustomApp) {
        $result->run();
        return true; // Handled
    }
    return null; // Not handled, continue to next listener
});

return $router;
```

**Use cases:**
- Mounting custom PSR-15 applications
- Supporting custom framework integrations
- Adding domain-specific routing behavior

## Performance Considerations

### No Route Caching

Mini deliberately avoids route caching:
- File system checks are fast enough for most applications
- Pattern compilation is memoized per-request
- Simplicity over micro-optimization
- No cache invalidation complexity

### Pattern Compilation

PatternRouter memoizes compiled patterns within a single request:

```php
class PatternRouter
{
    private array $compiledPatterns = [];

    private function compilePattern(string $pattern): string
    {
        if (!isset($this->compiledPatterns[$pattern])) {
            $this->compiledPatterns[$pattern] = $this->doCompile($pattern);
        }
        return $this->compiledPatterns[$pattern];
    }
}
```

**Why per-request caching:**
- Router is Singleton (shared across requests)
- Compiled patterns can safely accumulate in memory
- No need to recompile same patterns across requests

## Security Design

### Path Traversal Prevention

All paths are normalized and validated:

```php
private function normalizePath(string $path): string
{
    // Remove query string
    $path = parse_url($path, PHP_URL_PATH) ?? '/';

    // Normalize slashes
    $path = str_replace('//', '/', $path);

    // Remove . and .. (path traversal)
    $parts = explode('/', $path);
    $safe = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($safe);
            continue;
        }
        $safe[] = $part;
    }

    return '/' . implode('/', $safe);
}
```

### Underscore File Protection

Files starting with `_` are not directly accessible:

```php
private function isPubliclyAccessible(string $filename): bool
{
    return !str_starts_with(basename($filename), '_')
        || basename($filename) === '__DEFAULT__.php';  // Exception
}
```

**Protected files:**
- `_helpers.php`
- `_shared.php`
- `_config.php`
- Any file starting with `_`

**Accessible via internal redirect only:**
- `__DEFAULT__.php` (executed, not served directly)

## Fiber Safety

Router is completely fiber-safe:

1. **No instance state** - All request-specific state in local variables
2. **Request attributes** - State travels with the request object
3. **Immutable requests** - PSR-7 withX() methods create new instances
4. **Callback-based updates** - No shared mutable state

**Example of concurrent requests:**

```php
// Two fibers handling concurrent requests
Fiber::suspend();  // Fiber 1 pauses mid-request
// Fiber 2 starts handling its request
Router::handle($request2);  // Different $redirectCount, different $request
// Fiber 1 resumes
Router::handle($request1);  // Original $redirectCount, original $request
```

Each fiber's local variables are preserved when it suspends and resumes.

## Testing Routing

Key test scenarios:

1. **File-based routing** - Direct path to file
2. **Index files** - Directory roots
3. **Trailing slash** - Normalization behavior
4. **Pattern matching** - Dynamic routes with parameters
5. **Redirect loops** - Protection against infinite redirects
6. **Underscore protection** - Block direct access
7. **404 handling** - NotFoundException thrown
8. **PSR-15 mounting** - Custom request handlers
9. **Response conversion** - String/array to Response
10. **Fiber concurrency** - Multiple concurrent requests

## See Also

- `src/Router/README.md` - Developer-facing routing documentation
- `docs/dispatchers.md` - Dispatcher architecture
- `src/Dispatcher/HttpDispatcher.php` - Request lifecycle management
