# Http - PSR-7 HTTP Messages & Error Handling

This namespace provides HTTP-related utilities including PSR-7 message support and error handling.

## Purpose

Mini primarily uses native PHP (`$_GET`, `$_POST`, `header()`, etc.), but provides PSR-7 helpers when you need to integrate with PSR-7/PSR-15 middleware or libraries.

## PSR-7 Helpers

Convenience functions for working with PSR-7 HTTP messages (requires `nyholm/psr7` and `nyholm/psr7-server`):

```php
// Create PSR-7 request from globals
$request = \mini\Http\create_request_from_globals();

// Create responses
$response = \mini\Http\create_response(200, 'Hello World');
$jsonResponse = \mini\Http\create_json_response(['status' => 'ok']);

// Send response to client
\mini\Http\emit_response($response);
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
