# Controller - Attribute-Based Routing for Controllers

Mini's controller system provides clean, type-safe routing with automatic parameter extraction and return value conversion.

## Quick Start

```php
use mini\Controller\AbstractController;
use mini\Controller\Attributes\GET;
use mini\Controller\Attributes\POST;
use Psr\Http\Message\ResponseInterface;

class UserController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();
        $this->router->importRoutesFromAttributes($this);
    }

    #[GET('/')]
    public function index(): array
    {
        return ['users' => db()->query("SELECT * FROM users")->fetchAll()];
    }

    #[GET('/{id}/')]
    public function show(int $id): array
    {
        $user = db()->query("SELECT * FROM users WHERE id = ?", [$id])->fetch();
        if (!$user) throw new \mini\Http\NotFoundException();
        return $user;
    }

    #[POST('/')]
    public function create(): array
    {
        db()->exec(
            "INSERT INTO users (name, email) VALUES (?, ?)",
            [$_POST['name'], $_POST['email']]
        );
        return ['message' => 'Created', 'id' => db()->lastInsertId()];
    }
}
```

Mount in Mini router:

```php
// _routes/users/__DEFAULT__.php
return new UserController();
```

## Core Philosophy

**Controllers return data, not responses.** The converter registry automatically transforms return values to HTTP responses:

- `array` → JSON response
- `string` → text/plain response
- `ResponseInterface` → used directly
- Custom types → register converters

## Route Attributes

### HTTP Method Attributes

```php
use mini\Controller\Attributes\{GET, POST, PUT, PATCH, DELETE};

#[GET('/path')]      // GET requests
#[POST('/path')]     // POST requests
#[PUT('/path')]      // PUT requests
#[PATCH('/path')]    // PATCH requests
#[DELETE('/path')]   // DELETE requests
```

### Generic Route Attribute

```php
use mini\Controller\Attributes\Route;

#[Route('/path', method: 'GET')]
#[Route('/path', method: 'OPTIONS')]
```

### Multiple Routes on Same Method

Attributes are repeatable - register multiple routes:

```php
#[GET('/users/')]
#[GET('/people/')]
public function list(): array
{
    return ['users' => db()->query("SELECT * FROM users")->fetchAll()];
}
```

## Type-Aware URL Parameters

The router analyzes method signatures to extract and type-cast URL parameters:

```php
#[GET('/{id}/')]
public function show(int $id): array
{
    // $id is automatically extracted from URL and cast to int
    return ['id' => $id, 'type' => gettype($id)]; // "integer"
}

#[GET('/{slug}/')]
public function showBySlug(string $slug): array
{
    // $slug extracted as string
    return ['slug' => $slug];
}

#[GET('/posts/{postId}/comments/{commentId}/')]
public function showComment(int $postId, int $commentId): array
{
    // Multiple parameters, all type-cast
    return compact('postId', 'commentId');
}
```

**Supported types:**
- `int` → `\d+` pattern, cast to integer
- `float` → `\d+\.?\d*` pattern, cast to float
- `string` → `[^/]+` pattern, used as-is
- `bool` → `[01]|true|false` pattern, cast to boolean

## Return Value Conversion

Controllers can return any type - the converter registry handles transformation:

### Built-in Conversions

```php
// Array → JSON response
#[GET('/')]
public function index(): array
{
    return ['users' => [...]];  // Becomes application/json
}

// String → text/plain response
#[GET('/health/')]
public function health(): string
{
    return "OK";  // Becomes text/plain
}

// ResponseInterface → used directly
#[GET('/download/')]
public function download(): ResponseInterface
{
    return $this->redirect('/files/document.pdf');
}
```

### Custom Converters

Register converters for your domain objects:

```php
// bootstrap.php
$registry = Mini::$mini->get(ConverterRegistryInterface::class);

$registry->register(function(User $user): ResponseInterface {
    return new Response(
        json_encode($user->toArray()),
        ['Content-Type' => 'application/json'],
        200
    );
});
```

Then return domain objects directly:

```php
#[GET('/{id}/')]
public function show(int $id): User
{
    return table(User::class)->find($id);  // Converted to JSON automatically
}
```

## Helper Methods

AbstractController provides response helpers when you need explicit control:

```php
// JSON response
protected function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface

// HTML response
protected function html(string $body, int $status = 200, array $headers = []): ResponseInterface

// Plain text response
protected function text(string $body, int $status = 200, array $headers = []): ResponseInterface

// Empty response (204 No Content)
protected function empty(int $status = 204, array $headers = []): ResponseInterface

// Redirect
protected function redirect(string $url, int $status = 302): ResponseInterface

// Content negotiation (tries HTML view, falls back to JSON)
protected function respond(mixed $data, int $status = 200, array $headers = []): ResponseInterface
```

### Example Usage

```php
#[POST('/')]
public function create(): ResponseInterface
{
    db()->exec(
        "INSERT INTO users (name, email) VALUES (?, ?)",
        [$_POST['name'], $_POST['email']]
    );
    return $this->json(['id' => db()->lastInsertId()], 201);
}

#[GET('/download/')]
public function download(): ResponseInterface
{
    return $this->redirect('/files/document.pdf');
}
```

## Content Negotiation

The `respond()` helper checks the `Accept` header and serves HTML or JSON:

```php
#[GET('/{id}/')]
public function show(int $id): ResponseInterface
{
    $user = db()->query("SELECT * FROM users WHERE id = ?", [$id])->fetch();

    // If client accepts HTML and _views/users/show.php exists → HTML
    // Otherwise → JSON
    return $this->respond($user);
}
```

View mapping:
- `UserController::index()` → `_views/users/index.php`
- `UserController::show()` → `_views/users/show.php`
- `UserController::edit()` → `_views/users/edit.php`

## Trailing Slash Handling

Routes enforce trailing slash consistency with 301 redirects:

```php
#[GET('/users/')]      // Expects trailing slash
public function index() { }

// GET /users  → 301 redirect to /users/
// GET /users/ → 200 OK (matches)
```

```php
#[GET('/posts')]       // No trailing slash
public function posts() { }

// GET /posts/ → 301 redirect to /posts
// GET /posts  → 200 OK (matches)
```

**Root path `/` is special** - never redirected.

## Manual Route Registration (Alternative)

You can register routes manually instead of using attributes:

```php
class UserController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();

        // Manual registration
        $this->router->get('/', $this->index(...));
        $this->router->get('/{id}/', $this->show(...));
        $this->router->post('/', $this->create(...));
        $this->router->put('/{id}/', $this->update(...));
        $this->router->delete('/{id}/', $this->delete(...));
    }

    public function index(): array { return ['users' => []]; }
    public function show(int $id): array { return ['id' => $id]; }
    public function create(): array { return ['message' => 'Created']; }
    public function update(int $id): array { return ['message' => 'Updated']; }
    public function delete(int $id): ResponseInterface { return $this->empty(204); }
}
```

## Architecture

### Request Flow

1. **Mini router** dispatches to controller
2. **AbstractController::handle()** receives PSR-7 ServerRequest
3. **Router::match()** finds matching route and extracts URL parameters
4. **AbstractController** enriches request with parameters as attributes
5. **ConverterHandler** invokes controller method with parameters
6. **Converter registry** transforms return value to ResponseInterface

### Components

**Router** (src/Controller/Router.php)
- Pure routing logic (no PSR-15 interfaces)
- `match()` returns `['handler' => \Closure, 'params' => array]`
- Type-aware parameter extraction from URL patterns

**AbstractController** (src/Controller/AbstractController.php)
- Implements PSR-15 RequestHandlerInterface
- Orchestrates routing, parameter injection, and response conversion
- Provides response helper methods

**ConverterHandler** (src/Controller/ConverterHandler.php)
- Extracts parameters from request attributes
- Invokes controller methods via closures
- Converts return values using converter registry

**Route Attributes** (src/Controller/Attributes/)
- Declarative routing via PHP attributes
- `#[GET]`, `#[POST]`, `#[PUT]`, `#[PATCH]`, `#[DELETE]`
- Generic `#[Route]` for custom methods

## Common Patterns

### REST API Controller

```php
class PostController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();
        $this->router->importRoutesFromAttributes($this);
    }

    #[GET('/')]
    public function index(): array
    {
        return iterator_to_array(Post::query()->limit(100));
    }

    #[GET('/{id}/')]
    public function show(int $id): Post
    {
        return Post::find($id) ?? throw new \mini\Exceptions\NotFoundException();
    }

    #[POST('/')]
    public function create(): array
    {
        $post = new Post($_POST);
        $post->save();
        return ['id' => $post->id];
    }

    #[PUT('/{id}/')]
    public function update(int $id): Post
    {
        $post = Post::find($id) ?? throw new \mini\Exceptions\NotFoundException();
        $post->fill($_POST);
        $post->save();
        return $post;
    }

    #[DELETE('/{id}/')]
    public function delete(int $id): ResponseInterface
    {
        $post = Post::find($id) ?? throw new \mini\Exceptions\NotFoundException();
        $post->delete();
        return $this->empty(204);
    }
}
```

### Authentication Guard

```php
class AdminController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();

        // Guard all routes - runs before routing
        if (!auth()->check() || !auth()->user()->isAdmin()) {
            throw new AccessDeniedException();
        }

        $this->router->importRoutesFromAttributes($this);
    }

    #[GET('/')]
    public function dashboard(): array
    {
        return ['stats' => $this->getStats()];
    }
}
```

### Nested Resources

```php
class CommentController extends AbstractController
{
    public function __construct()
    {
        parent::__construct();
        $this->router->importRoutesFromAttributes($this);
    }

    #[GET('/posts/{postId}/comments/')]
    public function index(int $postId): array
    {
        return db()->query(
            "SELECT * FROM comments WHERE post_id = ?",
            [$postId]
        )->fetchAll();
    }

    #[POST('/posts/{postId}/comments/')]
    public function create(int $postId): array
    {
        db()->exec(
            "INSERT INTO comments (post_id, content) VALUES (?, ?)",
            [$postId, $_POST['content']]
        );
        return ['id' => db()->lastInsertId()];
    }
}
```

## Error Handling

Throw HTTP exceptions - they're automatically converted to responses:

```php
use mini\Http\{NotFoundException, AccessDeniedException, BadRequestException};

#[GET('/{id}/')]
public function show(int $id): array
{
    $user = db()->query("SELECT * FROM users WHERE id = ?", [$id])->fetch();

    if (!$user) {
        throw new NotFoundException("User not found");
    }

    if (!auth()->canView($user)) {
        throw new AccessDeniedException();
    }

    return $user;
}
```

## See Also

- **Converter System** - src/Converter/README.md
- **Database** - src/Database/README.md
- **Authentication** - src/Auth/README.md
