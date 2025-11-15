# Building Web Applications with Mini

This guide covers all aspects of building web applications with Mini: routing, error handling, response converters, and web app patterns.

## Table of Contents

- [File-Based Routing](#file-based-routing)
- [Controller-Based Routing](#controller-based-routing)
- [Exception Handling](#exception-handling)
- [Response Converters](#response-converters)
- [Custom Error Pages](#custom-error-pages)
- [Mounting Sub-Applications](#mounting-sub-applications)

## File-Based Routing

**The file system IS the router.** Files in `_routes/` map directly to URL paths.

### Basic Mapping

```
URL: /users          → _routes/users.php
URL: /users/         → _routes/users/index.php
URL: /api/posts      → _routes/api/posts.php
URL: /blog/about     → _routes/blog/about.php
```

### Wildcard Routing

Use `_.php` files to capture dynamic URL segments:

```php
// _routes/users/_.php - Matches /users/123
$userId = $_GET[0];
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
echo json_encode($user);
```

```php
// _routes/users/_/posts/_.php - Matches /users/{userId}/posts/{postId}
$userId = $_GET[0];   // First wildcard
$postId = $_GET[1];   // Second wildcard
$post = db()->queryOne("SELECT * FROM posts WHERE id = ? AND user_id = ?", [$postId, $userId]);
echo json_encode($post);
```

**Wildcard behavior:**
- `_.php` matches any single segment (e.g., `/users/123`)
- `_/index.php` matches any single segment with trailing slash (e.g., `/users/123/`)
- Exact matches take precedence over wildcards
- Captured values stored in `$_GET[0]`, `$_GET[1]`, etc. (left to right)
- Wildcards match single segments only (won't match across `/`)

### Trailing Slash Redirects

The router automatically redirects to ensure consistency:
- If only `_.php` exists: `/users/123/` → 301 redirect to `/users/123`
- If only `_/index.php` exists: `/users/123` → 301 redirect to `/users/123/`
- If both exist: Each URL serves its respective file (no redirect)

### What Route Files Can Return

Route files can return different types of values:

```php
// 1. Nothing (native PHP output)
header('Content-Type: application/json');
echo json_encode(['users' => db()->query("SELECT * FROM users")->fetchAll()]);
```

```php
// 2. PSR-7 Response
return response()->json(['users' => db()->query("SELECT * FROM users")->fetchAll()]);
```

```php
// 3. String or array (auto-converted via converters)
return ['users' => db()->query("SELECT * FROM users")->fetchAll()];  // → JSON response
return "Hello, world!";  // → Text/plain response
```

```php
// 4. PSR-15 RequestHandlerInterface
return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response('Hello');
    }
};
```

## Controller-Based Routing

**File-based routing doesn't mean "no OOP."** Use `__DEFAULT__.php` to mount controllers with attribute-based routing.

### Basic Controller

```php
// _routes/users/__DEFAULT__.php - Handles /users/*
use mini\Controller\AbstractController;
use mini\Controller\Attributes\{GET, POST, PUT, DELETE};

return new class extends AbstractController {
    #[GET('/')]
    public function index(): array
    {
        return db()->query("SELECT * FROM users")->fetchAll();
    }

    #[GET('/{id}/')]
    public function show(int $id): array
    {
        $user = db()->query("SELECT * FROM users WHERE id = ?", [$id])->fetch();
        if (!$user) throw new \mini\Exceptions\ResourceNotFoundException();
        return $user;
    }

    #[POST('/')]
    public function create(): array
    {
        $id = db()->insert('users', $_POST);
        return ['id' => $id, 'message' => 'Created'];
    }

    #[PUT('/{id}/')]
    public function update(int $id): array
    {
        db()->update('users', $_POST, 'id = ?', [$id]);
        return ['message' => 'Updated'];
    }

    #[DELETE('/{id}/')]
    public function delete(int $id): ResponseInterface
    {
        db()->delete('users', 'id = ?', [$id]);
        return $this->empty(204);
    }
};
```

### Key Features

**Scoped routing:** The controller only sees relative paths. `/users/123/` becomes `/{id}/` inside the controller.

**Type-aware parameters:** `int $id` automatically extracts and casts the URL parameter:
```php
#[GET('/{id}/')]
public function show(int $id): array  // $id is already an integer!
```

**Converter integration:** Return any type - controllers auto-convert to responses:
```php
return ['users' => $users];           // → JSON response
return "Hello";                       // → Text/plain response
return $this->json($data);            // → Explicit JSON response
return $this->html($html);            // → HTML response
return $this->redirect('/login');     // → Redirect response
return $this->empty(204);             // → Empty response (204 No Content)
```

**HTTP method routing:** Use method-specific attributes:
```php
#[GET('/users/')]           // Only GET requests
#[POST('/users/')]          // Only POST requests
#[PUT('/users/{id}/')]      // Only PUT requests
#[DELETE('/users/{id}/')]   // Only DELETE requests
#[Route('/users/', method: 'PATCH')]  // Custom method
```

### When to Use Controllers

Use controllers when you have:
- Multiple related endpoints (CRUD operations)
- Type-safe URL parameters
- Return value conversion needs (arrays → JSON)
- Clean, declarative routing

Use file-based routing when you have:
- Simple, independent endpoints
- Direct output control needs
- Maximum performance requirements

## Exception Handling

**Mini uses transport-agnostic exceptions.** The dispatcher maps them to appropriate HTTP responses.

### Domain Exceptions

```php
// Throw domain exceptions - dispatcher handles HTTP mapping
throw new \mini\Exceptions\ResourceNotFoundException('User not found');     // → 404
throw new \mini\Exceptions\AccessDeniedException('Login required');         // → 401/403
throw new \mini\Exceptions\BadRequestException('Invalid email format');     // → 400

// Generic exceptions become 500 errors
throw new \RuntimeException('Database connection failed');                  // → 500
```

### Exception Converters

Exception converters live in `src/Dispatcher/defaults.php` and map exceptions to HTTP responses:

```php
// ResourceNotFoundException → 404
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\ResourceNotFoundException $e): ResponseInterface {
    return new Response($html, ['Content-Type' => 'text/html; charset=utf-8'], 404);
});

// AccessDeniedException → 401/403 (smart detection)
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\AccessDeniedException $e): ResponseInterface {
    $statusCode = 401;  // Default: Unauthorized

    try {
        if (\mini\auth()->isAuthenticated()) {
            $statusCode = 403;  // User authenticated but lacks permission
        }
    } catch (\Throwable) {
        // Auth not configured, stay at 401
    }

    return new Response($html, ['Content-Type' => 'text/html; charset=utf-8'], $statusCode);
});
```

**You can register custom exception converters:**

```php
// _config/mini/Dispatcher/HttpDispatcher.php
$dispatcher = new HttpDispatcher($router);

$dispatcher->registerExceptionConverter(function(PaymentException $e): ResponseInterface {
    return new Response(
        json_encode(['error' => 'Payment failed', 'message' => $e->getMessage()]),
        ['Content-Type' => 'application/json'],
        402  // Payment Required
    );
});

return $dispatcher;
```

### Debug Mode vs Production

**Debug mode** shows beautiful exception details with stack traces:

```php
Mini::$mini->debug = true;  // Or set DEBUG=1 environment variable
```

In debug mode, exceptions show:
- Exception class name
- Error message
- File and line number
- Full stack trace
- Dark-themed, monospace display

**Production mode** shows clean, minimal error pages.

## Response Converters

Converters transform controller return values into PSR-7 responses.

### Built-In Converters

Registered in `src/Dispatcher/defaults.php`:

```php
// string → text/plain response
$converters->register(function(string $content): ResponseInterface {
    return new Response($content, ['Content-Type' => 'text/plain; charset=utf-8'], 200);
});

// array → JSON response
$converters->register(function(array $data): ResponseInterface {
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return new Response($json, ['Content-Type' => 'application/json; charset=utf-8'], 200);
});

// ResponseInterface → passthrough
$converters->register(function(ResponseInterface $response): ResponseInterface {
    return $response;
});
```

### Custom Converters

Register custom converters for your domain objects:

```php
// _config/mini/Converter/ConverterRegistryInterface.php
$registry = new ConverterRegistry();

// Convert User objects to JSON responses
$registry->register(function(User $user): ResponseInterface {
    return new Response(
        json_encode($user->toArray()),
        ['Content-Type' => 'application/json'],
        200
    );
});

// Convert HtmlPage objects to HTML responses
$registry->register(function(HtmlPage $page): ResponseInterface {
    return new Response(
        $page->render(),
        ['Content-Type' => 'text/html; charset=utf-8'],
        200
    );
});

return $registry;
```

**Now controllers can return domain objects directly:**

```php
#[GET('/{id}/')]
public function show(int $id): User
{
    return User::find($id);  // Converter handles Response creation
}
```

## Custom Error Pages

Create custom error page templates in the project root:

```php
// 404.php - Custom 404 page
<!DOCTYPE html>
<html>
<head>
    <title>Page Not Found</title>
</head>
<body>
    <h1>Oops! Page Not Found</h1>
    <p>The page you're looking for doesn't exist.</p>

    <?php if (isset($exception)): ?>
        <p>Error: <?= htmlspecialchars($exception->getMessage()) ?></p>
    <?php endif; ?>

    <a href="/">Go Home</a>
</body>
</html>
```

```php
// 500.php - Custom 500 page
<!DOCTYPE html>
<html>
<head>
    <title>Server Error</title>
</head>
<body>
    <h1>Something Went Wrong</h1>

    <?php if (\mini\Mini::$mini->debug && isset($exception)): ?>
        <pre><?= htmlspecialchars($exception->getMessage()) ?></pre>
        <pre><?= htmlspecialchars($exception->getTraceAsString()) ?></pre>
    <?php else: ?>
        <p>We're working on fixing this. Please try again later.</p>
    <?php endif; ?>
</body>
</html>
```

**Error page variables:**
- `$exception` - The exception that was thrown (Throwable)
- `\mini\Mini::$mini->debug` - Check if debug mode is enabled

**Standard HTTP status codes:**
- `400.php` - Bad Request
- `401.php` - Unauthorized
- `403.php` - Forbidden
- `404.php` - Not Found
- `500.php` - Internal Server Error

## Mounting Sub-Applications

Mini's zero-dependency design enables mounting entire frameworks as sub-applications without dependency conflicts.

### Mount a Slim 4 Application

```php
// _routes/api/__DEFAULT__.php
require_once __DIR__ . '/api-app/vendor/autoload.php';  // Slim's autoloader

use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->get('/users', function ($request, $response) {
    $users = db()->query("SELECT * FROM users")->fetchAll();
    $response->getBody()->write(json_encode($users));
    return $response->withHeader('Content-Type', 'application/json');
});

return $app;  // PSR-15 RequestHandlerInterface
```

Now `/api/users` is handled by Slim!

### Mount a Symfony Application

```php
// _routes/admin/__DEFAULT__.php
require_once __DIR__ . '/admin-app/vendor/autoload.php';

$kernel = new AppKernel('prod', false);
return $kernel;  // Symfony HttpKernelInterface wraps to PSR-15
```

### Why This Works

**Each sub-app can have different dependency versions:**

```
_routes/api/api-app/vendor/    # Slim 4 + guzzle 7.x
_routes/admin/admin-app/vendor/ # Symfony 6 + guzzle 6.x
vendor/                        # Mini (zero dependencies!)
```

No conflicts because:
- Mini has zero required dependencies
- Each sub-app loads its own autoloader
- Composer namespacing prevents collisions

## Dynamic Routes with __DEFAULT__.php

Handle dynamic segments with pattern matching:

```php
// _routes/blog/__DEFAULT__.php
return [
    '/' => 'index.php',                              // /blog/
    '/{slug}' => fn($slug) => "post.php?slug=$slug", // /blog/my-post
    '/{year}/{month}' => 'archive.php',              // /blog/2025/11
];
```

**Pattern features:**
- `{param}` captures any segment
- `{param:\d+}` captures with regex constraint
- Return string → internal redirect to that path
- Return false → 404
- Return array → additional routing table

## See Also

- **[src/Router/README.md](../src/Router/README.md)** - Detailed routing reference
- **[src/Controller/README.md](../src/Controller/README.md)** - Controller patterns and best practices
- **[src/Dispatcher/README.md](../src/Dispatcher/README.md)** - Dispatcher architecture and exception handling
- **[PATTERNS.md](../PATTERNS.md)** - Advanced patterns and techniques
