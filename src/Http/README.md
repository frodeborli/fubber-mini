# Http - PSR-7 HTTP Messages & Error Handling

This namespace provides HTTP-related utilities including PSR-7 message support and error handling.

## Purpose

Mini primarily uses native PHP (`$_GET`, `$_POST`, `header()`, `echo`), but provides PSR-7 support for integrating with PSR-7/PSR-15 middleware or libraries.

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

Error pages are customizable via templates in `_errors/`:
- `_errors/404.php` - Not found errors
- `_errors/401.php` - Unauthorized
- `_errors/403.php` - Forbidden
- `_errors/500.php` - Server errors

## HTTP Exceptions

Throw HTTP exceptions to trigger specific error pages:

```php
// Throw 404
throw new \mini\Http\NotFoundException('Page not found');

// Throw 400
throw new \mini\Http\BadRequestException('Invalid input');

// Throw 401/403
throw new \mini\Http\AccessDeniedException('Login required');
```

The framework's error handler catches these and displays appropriate error pages.
