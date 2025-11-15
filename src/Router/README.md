# Router - File-Based Routing

## Philosophy

Mini's router is **convention-driven, not configuration-heavy**. URL paths map directly to files in `_routes/` directory. When you need dynamic routing, use `__DEFAULT__.php` files with pattern matching. No route caching, no route compilation—just simple file-based routing that works.

**Key Principles:**
- **File-based routing** - `/users` → `_routes/users.php`
- **Hierarchical scoping** - `__DEFAULT__.php` for dynamic routes within directories
- **Security by convention** - Files starting with `_` are NOT publicly accessible
- **Pattern matching** - FastRoute-inspired syntax: `{id}`, `{slug:\w+}`
- **Native PHP** - Routes use `$_GET`, `$_POST`, `$_COOKIE` directly (request-scoped, fiber-safe)

## Setup

No configuration needed! Router is automatically registered and available:

```php
// html/index.php (entry point)
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();
```

## Common Usage Examples

### File-Based Routing

```
URL: /users
File: _routes/users.php

URL: /api/posts
File: _routes/api/posts.php

URL: /admin/
File: _routes/admin/index.php
```

**Filesystem Wildcards:**

Use `_` as a directory or file name to match any single path segment:

```
URL: /users/123
File: _routes/users/_.php
Captured: $_GET[0] = '123'

URL: /users/456/
File: _routes/users/_/index.php
Captured: $_GET[0] = '456'

URL: /users/100/friendship/200
File: _routes/users/_/friendship/_.php
Captured: $_GET[0] = '100', $_GET[1] = '200'
```

**How it works:**
- Router tries exact match first, then falls back to `_` wildcard
- Wildcards match single segments only (won't match across `/`)
- Captured values stored in `$_GET[0]`, `$_GET[1]`, etc. (left to right)
- Works for both files (`_.php`) and directories (`_/index.php`)
- If no wildcard match, falls back to `__DEFAULT__.php`

**Example:**
```php
<?php
// _routes/users/_.php - handles /users/{anything}

$userId = $_GET[0];  // Captured from URL
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

echo json_encode($user);
```

**Security:**
```
URL: /api/_helpers
Result: 404 (files starting with _ are not publicly routable)

URL: /__DEFAULT__
Result: 404 (framework-reserved files not accessible)
```

### Simple Route Handler

```php
<?php
// _routes/users.php

header('Content-Type: application/json');
echo json_encode(db()->query("SELECT * FROM users"));
```

### Route with Parameters (via $_GET)

```php
<?php
// _routes/user.php

$id = $_GET['id'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID required']);
    exit;
}

$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$id]);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

echo json_encode($user);
```

### Dynamic Routing with Patterns

```php
<?php
// _routes/blog/__DEFAULT__.php

return [
    '/' => 'posts/index.php',  // /blog/ → _routes/blog/posts/index.php
    '/{slug}' => fn($slug) => "posts/view.php?slug=$slug",  // /blog/hello-world
    '/{year:\d{4}}/{month:\d{2}}' => fn($year, $month) => "posts/archive.php?year=$year&month=$month",
];
```

```php
<?php
// _routes/blog/posts/view.php

$slug = $_GET['slug'];
$post = db()->queryOne("SELECT * FROM posts WHERE slug = ?", [$slug]);

echo render('blog/post', ['post' => $post]);
```

### API Routes with RESTful Patterns

```php
<?php
// _routes/api/__DEFAULT__.php

return [
    '/users' => fn() => match($_SERVER['REQUEST_METHOD']) {
        'GET' => 'users/index.php',
        'POST' => 'users/create.php',
        default => false  // 404
    },
    '/users/{id:\d+}' => fn($id) => match($_SERVER['REQUEST_METHOD']) {
        'GET' => "users/show.php?id=$id",
        'PUT' => "users/update.php?id=$id",
        'DELETE' => "users/delete.php?id=$id",
        default => false
    },
];
```

### Protected Routes

```php
<?php
// _routes/admin/__DEFAULT__.php

// Require authentication for all admin routes
auth()->requireLogin()->requireRole('admin');

return [
    '/' => 'dashboard.php',
    '/users' => 'users.php',
    '/settings' => 'settings.php',
];
```

```php
<?php
// _routes/admin/dashboard.php

// Already authenticated via __DEFAULT__.php
$stats = getDashboardStats();
echo render('admin/dashboard', ['stats' => $stats]);
```

## Advanced Examples

### Nested Scoped Routes

```php
<?php
// _routes/api/v1/__DEFAULT__.php

return [
    '/posts' => 'posts/index.php',
    '/posts/{id:\d+}' => fn($id) => "posts/show.php?id=$id",
];
```

```php
<?php
// _routes/api/v2/__DEFAULT__.php

return [
    '/posts' => 'posts/index.php',  // Different implementation from v1
    '/posts/{uuid:[a-f0-9-]+}' => fn($uuid) => "posts/show.php?uuid=$uuid",
];
```

### Pattern Matching with Type Casting

```php
<?php
// _routes/products/__DEFAULT__.php

return [
    '/{id:\d+}' => function(int $id) {
        // $id is automatically cast to int based on type hint
        $product = db()->queryOne("SELECT * FROM products WHERE id = ?", [$id]);
        echo json_encode($product);
    },
];
```

### Conditional Routing

```php
<?php
// _routes/blog/__DEFAULT__.php

return [
    '/preview/{id:\d+}' => function($id) {
        // Only show preview if user is admin
        if (!auth()->hasRole('admin')) {
            return false;  // 404 for non-admins
        }
        return "posts/preview.php?id=$id";
    },
];
```

### Global Routes Configuration

```php
<?php
// config/routes.php (fallback when no file-based route matches)

return [
    '/old-blog/{slug}' => fn($slug) => "/blog/$slug",  // Redirect pattern
    '/legacy/users' => fn() => "/api/users",
];
```

### Trailing Slash Handling

Router automatically handles trailing slashes:
- `/users` exists → `/users/` redirects to `/users` (301)
- `/users/` exists → `/users` redirects to `/users/` (301)
- Both exist → Each URL serves its own file

```
_routes/users.php       → Handles /users
_routes/users/index.php → Handles /users/
```

## Route Handler Return Values

Handlers (callables) can return:

1. **String** - Treated as internal redirect to another route file
```php
return 'posts/view.php?id=123';
```

2. **false** - Triggers 404
```php
return false;
```

3. **null/void** - Handler echoed output directly
```php
echo json_encode($data);  // No return
```

## Pattern Syntax

| Pattern | Regex | Example Match |
|---------|-------|---------------|
| `{id}` | `[^/]+` | `/post/123` |
| `{slug}` | `[^/]+` | `/post/hello-world` |
| `{id:\d+}` | `\d+` | `/user/456` |
| `{slug:\w+}` | `\w+` | `/category/tech` |
| `{year:\d{4}}` | `\d{4}` | `/archive/2024` |
| `{uuid:[a-f0-9-]+}` | `[a-f0-9-]+` | `/item/a1b2-c3d4` |

## File Naming Conventions

| File | Purpose |
|------|---------|
| `index.php` | Handles directory root (e.g., `/api/`) |
| `users.php` | Handles specific path (e.g., `/users`) |
| `_.php` | Wildcard file - matches any single segment, captured in `$_GET[0]` |
| `_/index.php` | Wildcard directory - matches any single segment with trailing slash |
| `__DEFAULT__.php` | Dynamic routing configuration with pattern matching |
| `_helpers.php` | Internal helpers (NOT publicly routable) |
| `_shared.php` | Shared code (NOT publicly routable) |

## Configuration

**Config File:** `config/mini/Router/Router.php` (optional, defaults to simple Router instance)

**Environment Variables:** None - routing is convention-based

## Overriding the Service

```php
// config/mini/Router/Router.php

use mini\Router\Router;

// Pre-configure routes for entire application
$router = new Router([
    '/health' => fn() => '{"status":"ok"}',
]);

return $router;
```

## Error Handling

Router throws `mini\Http\NotFoundException` when no route matches, which the framework catches and routes to `_errors/404.php`:

```php
<?php
// _errors/404.php

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not Found', 'path' => $_SERVER['REQUEST_URI']]);
```

## Mounting PSR-15 Applications

Mini's router supports mounting PSR-15 compatible applications (like Slim, Mezzio, etc.) under specific paths:

### Mounting a Slim Application

```php
<?php
// _routes/api/__DEFAULT__.php

use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->get('/users', function($request, $response) {
    $response->getBody()->write(json_encode(['users' => [...]]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/users', function($request, $response) {
    // Handle user creation
    return $response->withStatus(201);
});

// Return the Slim app - it implements RequestHandlerInterface
return $app;
```

Now all requests to `/api/*` are handled by the Slim application.

### Custom Request Handler

```php
<?php
// _routes/custom/__DEFAULT__.php

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        $response = new \mini\Http\Message\Response('Custom handler response', [], 200);
        return $response->withHeader('X-Custom', 'true');
    }
};
```

### Registering Custom Handlers

You can register your own handlers for __DEFAULT__.php return values:

```php
<?php
// config/mini/Router/Router.php

$router = new mini\Router\Router();

// Register handler for custom types
$router->defaultHandlers->listen(function($result, $routeInfo) {
    if ($result instanceof MyCustomApp) {
        $result->run();
        return true; // Handled
    }
    return null; // Not handled
});

return $router;
```

### __DEFAULT__.php Return Value Handling

The router processes __DEFAULT__.php return values in this order:

1. **null** - Assumes response was sent directly (like regular route files)
2. **Registered handlers** - Checks `$router->defaultHandlers` listeners
3. **PSR-15 RequestHandler** - Built-in support via `RequestHandlerInterface`
4. **Array** - Treats as route patterns (default Mini behavior)

```php
<?php
// _routes/example/__DEFAULT__.php

// Option 1: Send response directly, return null
echo json_encode(['direct' => 'response']);
return null;

// Option 2: Return PSR-15 handler
return new SlimApp();

// Option 3: Return routes array
return [
    '/{id}' => fn($id) => "item.php?id=$id"
];
```

## Router Scope

Router is **Singleton** - one instance shared across the application lifecycle. Routes are resolved per-request but router configuration persists.
