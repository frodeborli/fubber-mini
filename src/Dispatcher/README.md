# HttpDispatcher - Request Lifecycle Manager

## Philosophy

HttpDispatcher manages the complete HTTP request lifecycle in Mini. It bootstraps PSR-7 requests, makes request globals fiber-safe, delegates to the router, handles exceptions, and emits responses. You typically don't interact with HttpDispatcher directly—it runs once at application startup.

**Key Principles:**
- **Single entry point** - `mini\dispatch()` in your `html/index.php`
- **Fiber-safe by default** - Replaces `$_GET`, `$_POST`, `$_COOKIE` with proxies
- **Exception-based control** - Exceptions convert to HTTP responses
- **PSR-7/PSR-15 compliant** - Uses ServerRequest and RequestHandler interfaces
- **Centralized lifecycle** - Manages request creation, routing, and response emission

## Setup

No configuration needed! Just call `mini\dispatch()` in your entry point:

```php
<?php
// html/index.php (entry point)
require_once __DIR__ . '/../vendor/autoload.php';
mini\dispatch();
```

## Request Lifecycle

HttpDispatcher follows this exact sequence:

1. **Register ServerRequest service** - Transient service returns current request
2. **Create PSR-7 ServerRequest** - Built from PHP request globals (`$_GET`, `$_POST`, etc.)
3. **Set current request** - Stores in `$currentServerRequest` property
4. **Install request global proxies** - Replaces `$_GET`, `$_POST`, `$_COOKIE` with fiber-safe proxies
5. **Trigger Ready phase** - Locks down service registration
6. **Dispatch to Router** - Delegates to `RequestHandlerInterface` (Router)
7. **Handle exceptions** - Converts exceptions to PSR-7 responses
8. **Emit response** - Sends status, headers, and body to browser

## Request Globals are Fiber-Safe

Mini replaces `$_GET`, `$_POST`, `$_COOKIE` with proxy objects during dispatch. This makes them **fiber-safe** without code changes:

```php
<?php
// _routes/users.php

// These work exactly like normal PHP arrays
$id = $_GET['id'];              // ✓ Works
$name = $_POST['name'];         // ✓ Works
$token = $_COOKIE['session'];   // ✓ Works

// But they're actually proxies that delegate to the current request
// This means concurrent fibers each see their own request data

// Modification throws exception (use PSR-7 methods instead)
$_GET['id'] = 123;  // ✗ Throws RuntimeException
```

**Why this matters:**
- Your code works unchanged when you add fiber-based concurrency
- No need for special "context" objects or thread-local storage
- Traditional PHP code (`$_GET['id']`) just works in concurrent scenarios

**Limitations:**
```php
is_array($_GET)  // Returns false (it's an ArrayAccess object)
isset($_GET['id'])  // ✓ Works
count($_GET)  // ✓ Works
foreach ($_GET as $k => $v)  // ✓ Works
```

## Exception Handling

**Mini uses transport-agnostic exceptions** that are mapped to HTTP responses by the dispatcher.

### Built-In Exception Converters

Default exception converters are registered in `src/Dispatcher/defaults.php`:

```php
// ResourceNotFoundException → 404 Not Found
$dispatcher->registerExceptionConverter(
    function(\mini\Exceptions\ResourceNotFoundException $e): ResponseInterface {
        // Shows debug page in debug mode, clean 404 page in production
        $html = /* ErrorHandler renders page */;
        return new Response($html, ['Content-Type' => 'text/html'], 404);
    }
);

// AccessDeniedException → 401/403 (smart detection)
$dispatcher->registerExceptionConverter(
    function(\mini\Exceptions\AccessDeniedException $e): ResponseInterface {
        // 401 if not authenticated, 403 if authenticated but no permission
        $statusCode = \mini\auth()->isAuthenticated() ? 403 : 401;
        return new Response($html, ['Content-Type' => 'text/html'], $statusCode);
    }
);

// BadRequestException → 400 Bad Request
$dispatcher->registerExceptionConverter(
    function(\mini\Exceptions\BadRequestException $e): ResponseInterface {
        return new Response($html, ['Content-Type' => 'text/html'], 400);
    }
);

// Generic exceptions → 500 Internal Server Error
$dispatcher->registerExceptionConverter(
    function(\Throwable $e): ResponseInterface {
        return new Response($html, ['Content-Type' => 'text/html'], 500);
    }
);
```

### Custom Exception Converters

Register custom exception converters for domain-specific exceptions:

```php
<?php
// _config/mini/Dispatcher/HttpDispatcher.php

use mini\Dispatcher\HttpDispatcher;
use Psr\Http\Message\ResponseInterface;

$dispatcher = new HttpDispatcher(/* ... */);

// Handle payment exceptions
$dispatcher->registerExceptionConverter(
    function(PaymentException $e): ResponseInterface {
        return new \mini\Http\Message\Response(
            json_encode(['error' => 'Payment failed', 'message' => $e->getMessage()]),
            ['Content-Type' => 'application/json'],
            402  // Payment Required
        );
    }
);

// Handle validation errors
$dispatcher->registerExceptionConverter(
    function(\mini\Validator\ValidationException $e): ResponseInterface {
        $json = json_encode(['errors' => $e->errors]);
        return new \mini\Http\Message\Response(
            $json,
            ['Content-Type' => 'application/json'],
            400
        );
    }
);

return $dispatcher;
```

### Exception Converter Precedence

Exception converters are tried in order from **most specific to most generic**:

1. **Exact type match** - `PaymentException` converter for `PaymentException`
2. **Parent class match** - `\RuntimeException` converter for exceptions extending it
3. **Interface match** - Converters for implemented interfaces
4. **Generic fallback** - `\Throwable` converter catches everything

**If no converter returns a Response**, the exception propagates and is handled by the fatal error handler.

### Debug Mode

In debug mode (`Mini::$mini->debug = true`), exception pages show:
- Exception class name
- Error message
- File and line number
- Full stack trace
- Beautiful dark-themed display

In production, clean minimal error pages are shown instead.

## Accessing the Current Request

Use `mini\request()` to get the current PSR-7 ServerRequest:

```php
<?php
// _routes/api/upload.php

$request = mini\request();

// Access uploaded files
$files = $request->getUploadedFiles();
$avatar = $files['avatar'] ?? null;

// Access headers
$authHeader = $request->getHeaderLine('Authorization');

// Access parsed body (JSON, form data, etc.)
$data = $request->getParsedBody();

// Access query parameters (same as $_GET)
$params = $request->getQueryParams();
```

**When to use PSR-7 vs request globals:**
- Use `$_GET['id']` for simple parameter access (most common)
- Use `mini\request()` when you need headers, uploaded files, or PSR-7 methods

## Custom Dispatchers

You can create custom dispatchers for CLI, WebSocket, or other contexts:

```php
<?php
namespace mini\Dispatcher;

use mini\Mini;

class CliDispatcher
{
    public function dispatch(array $argv): void
    {
        // 1. Parse CLI arguments
        $command = $argv[1] ?? 'help';

        // 2. Trigger Ready phase
        Mini::$mini->phase->trigger(\mini\Phase::Ready);

        // 3. Execute command
        $handler = Mini::$mini->get(\mini\CLI\CommandHandler::class);
        $exitCode = $handler->handle($command, array_slice($argv, 2));

        // 4. Exit with code
        exit($exitCode);
    }
}
```

```php
<?php
// bin/cli.php
require_once __DIR__ . '/../vendor/autoload.php';

$dispatcher = Mini::$mini->get(\mini\Dispatcher\CliDispatcher::class);
$dispatcher->dispatch($argv);
```

## Configuration

**Service Registration:** `config/mini/Dispatcher/HttpDispatcher.php` (optional)

```php
<?php
// config/mini/Dispatcher/HttpDispatcher.php

use mini\Dispatcher\HttpDispatcher;

$dispatcher = new HttpDispatcher();

// Pre-register exception converters for entire application
$dispatcher->registerExceptionConverter(function(\Exception $e) {
    // Custom handling
});

return $dispatcher;
```

**Environment Variables:** None - HttpDispatcher uses Mini singleton settings

## Advanced: Response Already Sent

If you use classical PHP (`echo`, `header()`) in your routes, throw `ResponseAlreadySentException` to signal that the response was already sent:

```php
<?php
// _routes/legacy/endpoint.php

// Old-school PHP output
header('Content-Type: text/plain');
echo "Hello World";

// Signal that response was already sent (optional - dispatcher handles this automatically)
throw new \mini\Http\ResponseAlreadySentException();
```

HttpDispatcher catches this exception and skips response emission.

## Dispatcher Scope

HttpDispatcher is **Singleton** - one instance manages all requests. However, `$currentServerRequest` is updated per-request, making it fiber-safe for concurrent request handling.

## See Also

- **[docs/web-apps.md](../../docs/web-apps.md)** - Complete web app guide (routing, error handling, converters)
- **[docs/dispatchers.md](../../docs/dispatchers.md)** - Framework internals and architecture
- **[src/Router/README.md](../Router/README.md)** - Routing documentation
- **[src/Controller/README.md](../Controller/README.md)** - Controller patterns and best practices
