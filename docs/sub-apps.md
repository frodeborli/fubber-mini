# Building Sub-Applications with Controllers

This tutorial shows how to build self-contained sub-applications that mount at a URL path and handle all requests beneath it. This pattern is useful for:

- Admin panels (`/admin/...`)
- API versioning (`/api/v2/...`)
- Documentation browsers (`/docs/...`)
- User dashboards (`/dashboard/...`)
- Multi-tenant sections (`/org/{orgId}/...`)

## Quick Start

A minimal sub-application:

```php
<?php
// _routes/admin/__DEFAULT__.php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use mini\Http\Message\HtmlResponse;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('mini.router.path', '');
        $path = trim($path, '/');

        return match ($path) {
            '', 'index' => $this->dashboard(),
            'users' => $this->users(),
            'settings' => $this->settings(),
            default => throw new \mini\Exceptions\NotFoundException(),
        };
    }

    private function dashboard(): ResponseInterface
    {
        return new HtmlResponse('<h1>Admin Dashboard</h1>');
    }

    private function users(): ResponseInterface
    {
        return new HtmlResponse('<h1>User Management</h1>');
    }

    private function settings(): ResponseInterface
    {
        return new HtmlResponse('<h1>Settings</h1>');
    }
};
```

This handles:
- `/admin/` → dashboard
- `/admin/users` → user management
- `/admin/settings` → settings page

## The `__DEFAULT__.php` Pattern

When Mini's router encounters a path like `/admin/users/edit`, it looks for route files in this order:

1. `_routes/admin/users/edit.php` (exact match)
2. `_routes/admin/users/__DEFAULT__.php` (catch-all in users/)
3. `_routes/admin/__DEFAULT__.php` (catch-all in admin/)

The `__DEFAULT__.php` file receives all unmatched requests beneath its directory. The remaining path is available via:

```php
$path = $request->getAttribute('mini.router.path');
// For /admin/users/edit → "users/edit"
```

## Project Structure

For larger sub-applications, create a proper class:

```
mysite/
├── _routes/
│   ├── admin/
│   │   └── __DEFAULT__.php      # return new AdminPanel();
│   └── docs/
│       └── __DEFAULT__.php      # return new DocsViewer();
├── src/
│   ├── AdminPanel.php           # Admin sub-application
│   └── DocsViewer.php           # Documentation sub-application
└── _views/
    ├── admin/
    │   ├── layout.php
    │   ├── dashboard.php
    │   └── users.php
    └── docs/
        └── layout.php
```

The route file is minimal:

```php
<?php
// _routes/admin/__DEFAULT__.php
use App\AdminPanel;
return new AdminPanel();
```

## Building a Sub-Application Class

### Basic Structure

```php
<?php
namespace App;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use mini\Http\Message\HtmlResponse;
use mini\Exceptions\NotFoundException;

class AdminPanel implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Get the path relative to mount point
        $path = $request->getAttribute('mini.router.path', '');
        $path = trim($path, '/');

        // Route to appropriate handler
        return $this->route($path, $request);
    }

    private function route(string $path, ServerRequestInterface $request): ResponseInterface
    {
        // Handle index
        if ($path === '' || $path === 'index') {
            return $this->dashboard();
        }

        // Parse path segments
        $segments = explode('/', $path);
        $resource = $segments[0];
        $action = $segments[1] ?? 'index';
        $id = $segments[2] ?? null;

        return match ($resource) {
            'users' => $this->handleUsers($action, $id),
            'posts' => $this->handlePosts($action, $id),
            'settings' => $this->settings(),
            default => throw new NotFoundException("Page not found: $path"),
        };
    }

    private function dashboard(): ResponseInterface
    {
        return new HtmlResponse(\mini\render('admin/dashboard.php', [
            'stats' => $this->getStats(),
        ]));
    }

    private function handleUsers(string $action, ?string $id): ResponseInterface
    {
        return match ($action) {
            'index', '' => $this->userList(),
            'create' => $this->userCreate(),
            'edit' => $this->userEdit((int) $id),
            'delete' => $this->userDelete((int) $id),
            default => throw new NotFoundException(),
        };
    }

    private function handlePosts(string $action, ?string $id): ResponseInterface
    {
        // Similar pattern...
    }
}
```

### Constructor Injection

Sub-applications often need configuration. Pass it through the constructor:

```php
<?php
namespace App;

class DocsViewer implements RequestHandlerInterface
{
    private string $docsPath;
    private string $title;

    public function __construct(
        string $docsPath,
        string $title = 'Documentation'
    ) {
        $this->docsPath = $docsPath;
        $this->title = $title;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('mini.router.path', '');
        // ... use $this->docsPath and $this->title
    }
}
```

Mount with configuration:

```php
<?php
// _routes/docs/__DEFAULT__.php
use App\DocsViewer;
return new DocsViewer(
    docsPath: __DIR__ . '/../../docs',
    title: 'API Documentation'
);
```

## Authentication Guards

Check authentication in the constructor or at the start of `handle()`:

```php
class AdminPanel implements RequestHandlerInterface
{
    public function __construct()
    {
        // Guard: require admin role
        if (!\mini\session()->get('user_id')) {
            throw new \mini\Router\Redirect('/login?return=/admin/');
        }

        $user = $this->getCurrentUser();
        if (!$user->isAdmin()) {
            throw new \mini\Exceptions\AccessDeniedException('Admin access required');
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // All requests here are authenticated admins
    }
}
```

## Using AbstractController

For sub-applications that mostly follow REST patterns, extend `AbstractController`:

```php
<?php
namespace App;

use mini\Controller\AbstractController;
use mini\Controller\Attributes\GET;
use mini\Controller\Attributes\POST;

class AdminPanel extends AbstractController
{
    public function __construct()
    {
        parent::__construct();
        // Routes are registered from attributes automatically
    }

    #[GET('/')]
    public function dashboard(): array
    {
        return ['stats' => $this->getStats()];
    }

    #[GET('/users/')]
    public function users(): array
    {
        return ['users' => $this->getAllUsers()];
    }

    #[GET('/users/{id}/')]
    public function showUser(int $id): array
    {
        return $this->findUser($id);
    }

    #[POST('/users/')]
    public function createUser(): array
    {
        // Create user from $_POST
        return ['id' => $newId];
    }
}
```

**When to use AbstractController:**
- REST-style CRUD operations
- JSON API endpoints
- When attribute routing fits your needs

**When to implement RequestHandlerInterface directly:**
- Complex routing logic (regex patterns, wildcards)
- Constructor parameters needed
- Non-REST patterns (documentation browsers, wizards, file managers)

## Real-World Example: Documentation Browser

Here's a complete example of a reusable documentation sub-application:

```php
<?php
namespace App;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use mini\Http\Message\HtmlResponse;
use mini\Exceptions\NotFoundException;

/**
 * Reusable documentation browser
 *
 * Mount at any path to serve markdown documentation:
 *
 *   // _routes/docs/__DEFAULT__.php
 *   return new DocsBrowser(__DIR__ . '/../../docs');
 *
 *   // _routes/api-docs/__DEFAULT__.php
 *   return new DocsBrowser(__DIR__ . '/../../api-docs', title: 'API Reference');
 */
class DocsBrowser implements RequestHandlerInterface
{
    private string $docsPath;
    private string $title;

    public function __construct(string $docsPath, string $title = 'Documentation')
    {
        if (!is_dir($docsPath)) {
            throw new \InvalidArgumentException("Docs path not found: $docsPath");
        }
        $this->docsPath = realpath($docsPath);
        $this->title = $title;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('mini.router.path', '');
        $path = trim($path, '/');

        // Index page - show table of contents
        if ($path === '' || $path === 'index') {
            return $this->renderIndex();
        }

        // Try to find markdown file
        $filePath = $this->resolvePath($path);

        if ($filePath === null) {
            throw new NotFoundException("Page not found: $path");
        }

        return $this->renderMarkdown($filePath, $path);
    }

    private function resolvePath(string $path): ?string
    {
        // Security: prevent directory traversal
        $normalized = str_replace('..', '', $path);

        // Try exact match with .md extension
        $fullPath = $this->docsPath . '/' . $normalized . '.md';
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        // Try as directory with index.md
        $indexPath = $this->docsPath . '/' . $normalized . '/index.md';
        if (file_exists($indexPath)) {
            return $indexPath;
        }

        // Try README.md in directory
        $readmePath = $this->docsPath . '/' . $normalized . '/README.md';
        if (file_exists($readmePath)) {
            return $readmePath;
        }

        return null;
    }

    private function renderIndex(): ResponseInterface
    {
        $files = $this->scanDocs($this->docsPath);

        return new HtmlResponse(\mini\render('docs/index.php', [
            'title' => $this->title,
            'files' => $files,
        ]));
    }

    private function renderMarkdown(string $filePath, string $urlPath): ResponseInterface
    {
        $markdown = file_get_contents($filePath);
        $html = $this->markdownToHtml($markdown);

        // Extract title from first H1
        $pageTitle = $this->extractTitle($markdown) ?? basename($urlPath);

        return new HtmlResponse(\mini\render('docs/page.php', [
            'title' => $pageTitle . ' - ' . $this->title,
            'content' => $html,
            'path' => $urlPath,
        ]));
    }

    private function scanDocs(string $dir, string $prefix = ''): array
    {
        $files = [];
        foreach (glob($dir . '/*.md') as $file) {
            $name = basename($file, '.md');
            $files[] = [
                'name' => $name,
                'path' => $prefix . $name,
                'title' => $this->extractTitle(file_get_contents($file)) ?? $name,
            ];
        }
        return $files;
    }

    private function markdownToHtml(string $markdown): string
    {
        // Use league/commonmark or similar
        $converter = new \League\CommonMark\MarkdownConverter(
            new \League\CommonMark\Environment\Environment([])
        );
        return $converter->convert($markdown)->getContent();
    }

    private function extractTitle(string $markdown): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
```

## Handling HTTP Methods

Check the request method when you need different behavior:

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $path = trim($request->getAttribute('mini.router.path', ''), '/');
    $method = $request->getMethod();

    // Handle form submissions
    if ($path === 'users/create') {
        return match ($method) {
            'GET' => $this->showCreateForm(),
            'POST' => $this->processCreateForm(),
            default => throw new \mini\Exceptions\MethodNotAllowedException(),
        };
    }

    // ... rest of routing
}
```

## Shared Templates

Sub-applications typically have their own layout:

```php
// _views/admin/layout.php
<?php $this->extend('layout.php'); ?>

<?php $this->block('content'); ?>
<div class="admin-wrapper">
    <nav class="admin-sidebar">
        <a href="/admin/">Dashboard</a>
        <a href="/admin/users/">Users</a>
        <a href="/admin/posts/">Posts</a>
        <a href="/admin/settings/">Settings</a>
    </nav>
    <main class="admin-content">
        <?php $this->show('admin_content'); ?>
    </main>
</div>
<?php $this->end(); ?>
```

Pages extend the admin layout:

```php
// _views/admin/users.php
<?php $this->extend('admin/layout.php'); ?>

<?php $this->block('admin_content'); ?>
<h1>User Management</h1>
<table>
    <?php foreach ($users as $user): ?>
        <tr>
            <td><?= htmlspecialchars($user['name']) ?></td>
            <td><?= htmlspecialchars($user['email']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php $this->end(); ?>
```

## Best Practices

1. **Keep route files minimal** - Just instantiate and return the controller. Put logic in src/ classes.

2. **Use constructor injection** - Pass configuration through the constructor, not globals.

3. **Guard early** - Check authentication/authorization in the constructor or at the start of `handle()`.

4. **Relative URLs in templates** - Use relative URLs so the sub-app can be mounted anywhere:
   ```php
   <a href="users/">Users</a>      <!-- Relative to current path -->
   <a href="../settings/">Settings</a>  <!-- Up one level -->
   ```

5. **Throw exceptions for errors** - Let Mini's error handling convert them to responses:
   ```php
   throw new NotFoundException("User not found");
   throw new AccessDeniedException();
   throw new \mini\Router\Redirect('/login');
   ```

6. **Implement RequestHandlerInterface** - Don't try to use `echo` or direct output. Return `ResponseInterface`.

7. **Use the mini.router.path attribute** - This gives you the path relative to your mount point, not the full URL.

## See Also

- **Controller** - src/Controller/README.md for REST-style controllers
- **Templates** - docs/templates.md for view inheritance
- **Routing** - src/Router/README.md for how Mini routes requests
