# Router - File-Based Routing

Filesystem-based routing with a strict, runtime-portable route-file contract — a Lindy convention (URLs map to files) a forkable core can keep stable for a decade.

## Philosophy

Mini's router is **convention-driven, not configuration-heavy**. URL paths map directly to files in `_routes/` directory. When you need dynamic routing, use `__DEFAULT__.php` files with pattern matching. No route caching, no route compilation—just simple file-based routing that works.

**Key Principles:**
- **File-based routing** - `/users` → `_routes/users.php`
- **Hierarchical scoping** - `__DEFAULT__.php` for dynamic routes within directories
- **Security by convention** - Files starting with `_` are NOT publicly accessible
- **Pattern matching** - FastRoute-inspired syntax: `{id}`, `{slug:\w+}`
- **Native PHP** - Handlers read `$_GET`, `$_POST`, `$_COOKIE` directly (request-scoped, fiber-safe)

**The route file contract:** a route file declares *what* handles a URL prefix. It must return a PSR-15 `RequestHandlerInterface` (typically a controller extending `mini\Controller\AbstractController`), a PSR-7 `ResponseInterface`, a `Closure` (an inline handler — its return value is converted to a response via the converter registry), or a `mini\Http\ResponseAggregate` (resolved via `getResponse()`). Producing output directly (`echo`, `header()`) or returning anything else throws a `RuntimeException` — direct output ties the application to one-process-per-request SAPIs and is not portable to Fiber-based coroutine runtimes.

## Setup

No configuration needed! Router is automatically registered and available:

```php
// html/index.php (entry point)
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\dispatch();
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

return function() {
    $userId = $_GET[0];  // Captured from URL
    $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$userId]);

    if (!$user) {
        throw new \mini\Exceptions\NotFoundException('User not found');
    }

    return $user;  // Converted to a JSON response via the converter registry
};
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

return fn() => db()->query("SELECT * FROM users")->all();  // array → JSON response
```

### Route with Parameters (via $_GET)

```php
<?php
// _routes/user.php

return function() {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        throw new \mini\Exceptions\BadRequestException('User ID required');
    }

    $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$id]);

    if (!$user) {
        throw new \mini\Exceptions\NotFoundException('User not found');
    }

    return $user;  // → JSON response
};
```

### Dynamic Routing with Patterns

For pattern-based sub-routing, the usual choice is mounting a controller (`return new BlogController();` — see [Controller documentation](../Controller/README.md)). For plain path remapping, `__DEFAULT__.php` can throw a `Reroute` with pattern → request-path mappings (targets are request paths, resolved from the `__DEFAULT__.php` directory — they may point to underscore-prefixed files):

```php
<?php
// _routes/blog/__DEFAULT__.php

use mini\Router\Reroute;

throw new Reroute([
    '/' => '_index',  // /blog/ → _routes/blog/_index.php
    '/{slug}' => fn($slug) => "_view?slug=$slug",  // /blog/hello-world
    '/{year:\d{4}}/{month:\d{2}}' => fn($year, $month) => "_archive?year=$year&month=$month",
]);
```

```php
<?php
// _routes/blog/_view.php

use mini\Http\Message\HtmlResponse;

$slug = $_GET['slug'];
$post = db()->queryOne("SELECT * FROM posts WHERE slug = ?", [$slug]);

return new HtmlResponse(render('blog/post', ['post' => $post]));
```

### API Routes with RESTful Patterns

Method-aware routing belongs in a controller — mount one for the subtree:

```php
<?php
// _routes/api/users/__DEFAULT__.php

use mini\Controller\AbstractController;
use mini\Controller\Attributes\{GET, POST, PUT, DELETE};

return new class extends AbstractController {
    #[GET('/')]
    public function index(): array
    {
        return db()->query("SELECT * FROM users")->all();
    }

    #[POST('/')]
    public function create(): array
    {
        db()->exec("INSERT INTO users (name) VALUES (?)", [$_POST['name']]);
        return ['id' => db()->lastInsertId()];
    }

    #[GET('/{id:\d+}/')]
    public function show(int $id): object
    {
        return db()->queryOne("SELECT * FROM users WHERE id = ?", [$id])
            ?? throw new \mini\Exceptions\NotFoundException();
    }
};
```

### Protected Routes

```php
<?php
// _routes/admin/__DEFAULT__.php

use mini\Router\Reroute;

// Require authentication for all admin routes
auth()->requireLogin()->requireRole('admin');

throw new Reroute([
    '/' => '_dashboard',
    '/users' => '_users',
    '/settings' => '_settings',
]);
```

```php
<?php
// _routes/admin/_dashboard.php

use mini\Http\Message\HtmlResponse;

// Already authenticated via __DEFAULT__.php
$stats = getDashboardStats();
return new HtmlResponse(render('admin/dashboard', ['stats' => $stats]));
```

## Advanced Examples

### Nested Scoped Routes

```php
<?php
// _routes/api/v1/__DEFAULT__.php

use mini\Router\Reroute;

throw new Reroute([
    '/posts' => '_posts/index',
    '/posts/{id:\d+}' => fn($id) => "_posts/show?id=$id",
]);
```

```php
<?php
// _routes/api/v2/__DEFAULT__.php

use mini\Router\Reroute;

throw new Reroute([
    '/posts' => '_posts/index',  // Different implementation from v1
    '/posts/{uuid:[a-f0-9-]+}' => fn($uuid) => "_posts/show?uuid=$uuid",
]);
```

### Pattern Matching with Type Casting

Typed URL parameters are a controller feature — declare the type on the method parameter:

```php
<?php
// _routes/products/__DEFAULT__.php

use mini\Controller\AbstractController;
use mini\Controller\Attributes\GET;

return new class extends AbstractController {
    #[GET('/{id:\d+}/')]
    public function show(int $id): object
    {
        // $id is automatically cast to int based on type hint
        return db()->queryOne("SELECT * FROM products WHERE id = ?", [$id])
            ?? throw new \mini\Exceptions\NotFoundException();
    }
};
```

### Conditional Routing

Guard inside the handler and throw to produce a 404 (or 401/403):

```php
<?php
// _routes/blog/_preview.php — target of '/preview/{id:\d+}' => fn($id) => "_preview?id=$id"

return function() {
    // Only show preview if user is admin
    if (!auth()->hasRole('admin')) {
        throw new \mini\Exceptions\NotFoundException();  // 404 for non-admins
    }
    $post = db()->queryOne("SELECT * FROM posts WHERE id = ?", [$_GET['id']]);
    return new \mini\Http\Message\HtmlResponse(render('blog/preview', ['post' => $post]));
};
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

## Route File Return Values

Every route file (including `__DEFAULT__.php`) must return one of:

1. **PSR-15 `RequestHandlerInterface`** - e.g. a controller extending `AbstractController`, or a mounted Slim/Mezzio app
```php
return new UserController();
```

2. **PSR-7 `ResponseInterface`** - a direct response
```php
return new \mini\Http\Message\Response('pong');
```

3. **`Closure`** - an inline handler with typed parameter injection; the return value is converted via the converter registry
```php
return fn() => ['time' => date('c')];  // array → JSON response
```

4. **`mini\Http\ResponseAggregate`** - resolved via `getResponse()`

Anything else — no return, `echo`/`header()` output, scalars, arrays — throws a `RuntimeException`. "Return data, get a response" applies to *Closure and controller-method return values*, not to the route file itself.

### Reroute Target Values

Patterns in a `Reroute` (thrown from `__DEFAULT__.php`) map to targets that are **request paths** (not filenames), resolved from the `__DEFAULT__.php` directory:

1. **String** - a request path, query parameters allowed
```php
'/create' => '_create',  // resolves to _create.php
```

2. **Closure** - invoked with the captured pattern parameters (matched by name); must return a request path string
```php
'/{id}/' => fn($id) => "_view?id=$id",
```

Reroute targets may point to underscore-prefixed files (internal routing), and the routed-to file is subject to the same return-value contract as any route file.

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

**Config File:** `_config/mini/Router/Router.php` (optional, defaults to simple Router instance)

**Environment Variables:** None - routing is convention-based

## Overriding the Service

```php
// _config/mini/Router/Router.php

// Return your own Router instance (or subclass) to customize routing
return new mini\Router\Router();
```

## Error Handling

Router throws `mini\Exceptions\NotFoundException` when no route matches. The dispatcher converts it to a 404 response, rendered from the `_views/errors/404.php` template if your application provides one (framework defaults otherwise):

```php
<?php // _views/errors/404.php - a template, rendered by the error handler ?>
<h1><?= mini\h(mini\t('Page not found')) ?></h1>
<p><?= mini\h($_SERVER['REQUEST_URI']) ?></p>
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

### __DEFAULT__.php Return Value Handling

`__DEFAULT__.php` follows the same contract as every other route file — it must return a `RequestHandlerInterface`, a `ResponseInterface`, a `Closure`, or a `ResponseAggregate`. When it returns a PSR-15 handler, the matched URL prefix is stripped from the request target so the handler sees paths scoped to its subtree:

```php
<?php
// _routes/example/__DEFAULT__.php

// Option 1: Mount a PSR-15 handler (controller, Slim app, ...) for the subtree
return new SlimApp();

// Option 2: Pattern-based path remapping via Reroute
throw new mini\Router\Reroute([
    '/{id}' => fn($id) => "_item?id=$id"
]);
```

## Router Scope

Router is **Singleton** - one instance shared across the application lifecycle. Routes are resolved per-request but router configuration persists.
