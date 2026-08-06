# Building Web Applications with Mini

This guide covers the main parts of building web applications with Mini: routing, error handling, response converters, and web app patterns.

## Table of Contents

- [File-Based Routing](#file-based-routing)
- [Controller-Based Routing](#controller-based-routing)
- [Exception Handling](#exception-handling)
- [Response Converters](#response-converters)
- [Custom Error Pages](#custom-error-pages)
- [Mounting Sub-Applications](#mounting-sub-applications)
- [Pattern-Based Rerouting](#pattern-based-rerouting)

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
use mini\Http\Message\JsonResponse;

$userId = $_GET[0];
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
return new JsonResponse($user);
```

```php
// _routes/users/_/posts/_.php - Matches /users/{userId}/posts/{postId}
use mini\Http\Message\JsonResponse;

$postId = $_GET[0];   // Nearest wildcard (the last one matched)
$userId = $_GET[1];   // Next wildcard, moving outward
$post = db()->queryOne("SELECT * FROM posts WHERE id = ? AND user_id = ?", [$postId, $userId]);
return new JsonResponse($post);
```

**Wildcard behavior:**
- `_.php` matches any single segment (e.g., `/users/123`)
- `_/index.php` matches any single segment with trailing slash (e.g., `/users/123/`)
- Exact matches take precedence over wildcards
- Captured values stored in `$_GET[0]`, `$_GET[1]`, etc. — **nearest wildcard first** (`$_GET[0]` is the wildcard closest to the matched file)
- Wildcards match single segments only (won't match across `/`)

### Trailing Slash Redirects

The router automatically redirects to ensure consistency:
- If only `_.php` exists: `/users/123/` → 301 redirect to `/users/123`
- If only `_/index.php` exists: `/users/123` → 301 redirect to `/users/123/`
- If both exist: Each URL serves its respective file (no redirect)

### What Route Files Must Return

A route file must return a PSR-15 `RequestHandlerInterface`, a PSR-7 `ResponseInterface`, a `Closure` (inline handler) or a `mini\Http\ResponseAggregate`. Anything else — no return, direct output (`echo`/`header()`), scalars or arrays returned from the file itself — throws a `RuntimeException`.

```php
// 1. PSR-7 Response
use mini\Http\Message\JsonResponse;
return new JsonResponse(['users' => db()->query("SELECT * FROM users")->all()]);
```

```php
// 2. PSR-7 HTML Response
use mini\Http\Message\HtmlResponse;
return new HtmlResponse(render('users.php', ['users' => $users]));
```

```php
// 3. Response class extending HtmlResponse (best for complex pages)
use mini\Http\Message\HtmlResponse;

class UsersPage extends HtmlResponse {
    public function __construct() {
        $users = db()->query("SELECT * FROM users")->all();
        parent::__construct(render('users.php', ['users' => $users]));
    }
}

return new UsersPage();
```

```php
// 4. PSR-15 RequestHandlerInterface
return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response('Hello');
    }
};
```

```php
// 5. Closure (inline handler) — typed parameter injection; the return value
//    is converted via the converter registry (array → JSON, etc.)
return fn() => ['users' => db()->query("SELECT * FROM users")->all()];
```

```php
// 6. Closure via first-class callable syntax — delegate to a method
return (new SupportTicket)->handleUserSupportTicket(...);
```

**Important:** When a Closure returns a plain string (or int/float/bool), the default converter JSON-encodes it into an `application/json` response — it does not become HTML. For HTML responses, always use `HtmlResponse` or a class that extends it.

### Mounting a module's handler method

When a route file returns a `Closure` (most commonly via PHP's first-class callable syntax `Method(...)`), Mini invokes it with **typed parameter injection** — the same mechanism that drives attribute-based controllers. This lets a route delegate to a service method in one line:

```php
// _routes/people/_/support-tickets.php
use mini\Mini;
use App\Support\SupportTicket;

return Mini::$mini->get(SupportTicket::class)->handleUserSupportTicket(...);
```

```php
// src/Support/SupportTicket.php
namespace App\Support;

use mini\Http\Message\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SupportTicket {
    public function handleUserSupportTicket(int $_0, ServerRequestInterface $request): ResponseInterface {
        // $_0 is the wildcard match — the person id from /people/{id}/support-tickets
        $tickets = db()->query("SELECT * FROM tickets WHERE user_id = ?", [$_0])->all();
        return new JsonResponse(['tickets' => $tickets]);
    }
}
```

**Parameter resolution rules** (applied in order, per parameter):

1. **`$_0`, `$_1`, ...** → positional wildcard captures, nearest wildcard first (same array as `$_GET[0]`, `$_GET[1]`). Builtin scalar types are coerced (`int $_0` → integer).
2. **Class-typed parameter** → if the type matches `ServerRequestInterface` (or a sub-type), the current request is injected. Otherwise the service container resolves it (`Mini::$mini->get($type)`).
3. **Match by parameter name** against request attributes (URL captures set by attribute-based routes live here, plus anything middleware added).
4. **Default value**, then nullable → `null`, then a `Missing required parameter` error.

The same rules apply to attribute-based controllers, so a single convention covers both routing styles.

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
        return db()->query("SELECT * FROM users")->all();
    }

    #[GET('/{id}/')]
    public function show(int $id): object
    {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user) throw new \mini\Exceptions\NotFoundException();
        return $user;
    }

    #[POST('/')]
    public function create(): array
    {
        $id = db()->insert('users', ['name' => $_POST['name'], 'email' => $_POST['email']]);
        return ['id' => $id, 'message' => 'Created'];
    }

    #[PUT('/{id}/')]
    public function update(int $id): array
    {
        db()->update(
            db()->query("SELECT * FROM users")->eq('id', $id),
            ['name' => $_POST['name'], 'email' => $_POST['email']]
        );
        return ['message' => 'Updated'];
    }

    #[DELETE('/{id}/')]
    public function delete(int $id): ResponseInterface
    {
        db()->delete(db()->query("SELECT * FROM users")->eq('id', $id));
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
return "Hello";                       // → JSON scalar response ("Hello")
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
- Handlers that are complete responses in themselves (`FileResponse`, `JsonResponse`, a `ResponseAggregate`)
- Maximum performance requirements

## Exception Handling

**Mini uses transport-agnostic exceptions.** The dispatcher maps them to appropriate HTTP responses.

### Domain Exceptions

```php
// Throw domain exceptions - dispatcher handles HTTP mapping
throw new \mini\Exceptions\NotFoundException('User not found');               // → 404
throw new \mini\Exceptions\AuthenticationRequiredException();                 // → 401 (not logged in)
throw new \mini\Exceptions\AccessDeniedException('Admins only');              // → 403 (logged in, not permitted)
throw new \mini\Exceptions\BadRequestException('Invalid email format');       // → 400

// Generic exceptions become 500 errors
throw new \RuntimeException('Database connection failed');                    // → 500
```

### Exception Converters

The default converters live in `src/Dispatcher/defaults.php` and map exceptions to HTTP responses rendered via `mini\Http\ErrorHandler`:

```php
// NotFoundException → 404
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\NotFoundException $e): ResponseInterface {
    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, 404);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], 404);
});
```

Defaults are registered for `NotFoundException` (404), `AuthenticationRequiredException` (401), `AccessDeniedException` (403), `BadRequestException` (400), and `\Throwable` (500 catch-all). The most specific matching converter wins.

**You can register custom exception converters** during bootstrap (e.g. in your application's `bootstrap.php`). Registering during the Bootstrap phase transparently replaces the framework default for the same exception type:

```php
// bootstrap.php
use mini\Mini;
use mini\Dispatcher\HttpDispatcher;

$dispatcher = Mini::$mini->get(HttpDispatcher::class);

$dispatcher->registerExceptionConverter(function(PaymentException $e): ResponseInterface {
    return new Response(
        json_encode(['error' => 'Payment failed', 'message' => $e->getMessage()]),
        ['Content-Type' => 'application/json'],
        402  // Payment Required
    );
});
```

### Debug Mode vs Production

**Debug mode** shows detailed exception pages with stack traces. `Mini::$mini->debug` is a readonly property set from the environment at startup:

```bash
DEBUG=1 php -S localhost:8080   # or set DEBUG=1 in your FPM/env configuration
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
// string|int|float|bool → JSON scalar response
$converters->register(function(string|int|float|bool $value): ResponseInterface {
    $json = json_encode($value, JSON_THROW_ON_ERROR);
    return new Response($json, ['Content-Type' => 'application/json; charset=utf-8'], 200);
});

// array|stdClass → JSON response (a JsonSerializable converter is also registered)
$converters->register(function(array|\stdClass $data): ResponseInterface {
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

Error pages are ordinary templates. `mini\Http\ErrorHandler` resolves them through the views path registry:

1. Application-specific: `_views/errors/{statusCode}.php`
2. Framework default: `vendor/fubber/mini/_views/errors/{statusCode}.php`
3. Debug mode fallback: `vendor/fubber/mini/_views/errors/debug.php`

```php
// _views/errors/404.php - Custom 404 page
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
// _views/errors/500.php - Custom 500 page
<!DOCTYPE html>
<html>
<head>
    <title>Server Error</title>
</head>
<body>
    <h1>Something Went Wrong</h1>
    <p>We're working on fixing this. Please try again later.</p>
</body>
</html>
```

**Error page variables:**
- `$exception` - The exception that was thrown (Throwable)
- `$message` - The exception message

In debug mode (`Mini::$mini->debug`, or the `DEBUG=1` environment variable) the ErrorHandler bypasses these templates entirely and renders a detailed debug page with stack trace and source context.

**Standard HTTP status codes:**
- `_views/errors/400.php` - Bad Request
- `_views/errors/401.php` - Unauthorized (authentication required)
- `_views/errors/403.php` - Forbidden (access denied)
- `_views/errors/404.php` - Not Found
- `_views/errors/500.php` - Internal Server Error

## Mounting Sub-Applications

Mini's zero-dependency design enables mounting entire frameworks as sub-applications without dependency conflicts.

### Mount a Slim 4 Application

```php
// _routes/api/__DEFAULT__.php
require_once __DIR__ . '/api-app/vendor/autoload.php';  // Slim's autoloader

use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->get('/users', function ($request, $response) {
    $users = \mini\db()->query("SELECT * FROM users")->all();
    $response->getBody()->write(json_encode($users));
    return $response->withHeader('Content-Type', 'application/json');
});

return $app;  // PSR-15 RequestHandlerInterface
```

Now `/api/users` is handled by Slim!

### Mount Any PSR-15 Application

The same pattern works for anything that implements (or can be adapted to) `Psr\Http\Server\RequestHandlerInterface`. For frameworks with their own request/response types (e.g. Symfony's HttpKernel), wrap the kernel in a small PSR-15 adapter — for Symfony, `symfony/psr-http-message-bridge` does the message conversion:

```php
// _routes/admin/__DEFAULT__.php
require_once __DIR__ . '/admin-app/vendor/autoload.php';

return new MyPsr15KernelAdapter(new AppKernel('prod', false));
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

## Pattern-Based Rerouting

`__DEFAULT__.php` follows the same contract as every other route file — it must return a handler or a response, never a bare array. For pattern-based path remapping, throw a `mini\Router\Reroute` with a pattern table; the router re-dispatches internally (no client-visible redirect):

```php
// _routes/blog/__DEFAULT__.php
throw new mini\Router\Reroute([
    '/{slug}' => fn($slug) => "_post?slug=$slug",  // /blog/my-post → _routes/blog/_post.php
]);
```

See `src/Router/README.md` for `Reroute` and `Redirect` details.

## See Also

- **[src/Router/README.md](../src/Router/README.md)** - Detailed routing reference
- **[src/Controller/README.md](../src/Controller/README.md)** - Controller patterns and best practices
- **[src/Dispatcher/README.md](../src/Dispatcher/README.md)** - Dispatcher architecture and exception handling
- **[PATTERNS.md](../PATTERNS.md)** - Advanced patterns and techniques
