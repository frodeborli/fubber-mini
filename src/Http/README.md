# Http - PSR-7 HTTP Messages & Error Handling

This namespace is Mini's own PSR-7/PSR-17 implementation plus error handling — the HTTP foundation a forkable core framework must own rather than depend on.

## Purpose

Mini reads request data via native PHP patterns (`$_GET`, `$_POST` — fiber-safe, request-scoped proxies) and produces output via PSR-7 responses. This namespace provides Mini's PSR-7 implementation, used both by routes and when integrating with PSR-7/PSR-15 middleware or libraries.

## PSR-7 Usage

Mini includes a native PSR-7 implementation. Use it when integrating with PSR-7/PSR-15 libraries:

```php
// Get current request
$request = \mini\request();
$query = $request->getQueryParams();
$body = $request->getParsedBody();

// Create responses
use mini\Http\Message\Response;
$response = new Response('Hello World', [], 200);
$jsonResponse = new Response(
    json_encode(['status' => 'ok']),
    ['Content-Type' => 'application/json'],
    200
);

// HttpDispatcher handles response emission automatically
return $response;
```

## Error Handling

The namespace includes `ErrorHandler` which provides Mini's error and exception handling system. It displays user-friendly error pages in production and detailed error information in debug mode.

Error pages are customizable via templates in `_views/errors/` (application templates override the framework defaults):
- `_views/errors/404.php` - Not found errors
- `_views/errors/401.php` - Unauthorized
- `_views/errors/403.php` - Forbidden
- `_views/errors/500.php` - Server errors

## HTTP Exceptions

Throw HTTP exceptions (from `mini\Exceptions`) to trigger specific error pages:

```php
// Throw 404
throw new \mini\Exceptions\NotFoundException('Page not found');

// Throw 400
throw new \mini\Exceptions\BadRequestException('Invalid input');

// Throw 401
throw new \mini\Exceptions\AuthenticationRequiredException('Login required');

// Throw 403
throw new \mini\Exceptions\AccessDeniedException('Not permitted');
```

The framework's error handler catches these and displays appropriate error pages.
