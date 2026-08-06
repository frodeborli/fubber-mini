# Auth - Authentication System

Auth is a thin facade over an application-provided `AuthInterface` — the kind of neutral seam a forkable core framework should own, leaving user models and credential storage to the layer above.

## Philosophy

Mini's auth system is **framework-agnostic by design**. We don't prescribe how you authenticate users—whether it's sessions, JWTs, API keys, or something custom. Instead, we provide a clean facade with convenience methods that delegate to your implementation.

**Key Principles:**
- **Your implementation, our convenience** - You provide `AuthInterface`, we provide `auth()` facade
- **No database coupling** - Auth doesn't assume how you store users
- **Minimal abstraction** - Direct access to your implementation when needed
- **Fluent API** - Chain authorization checks: `auth()->requireLogin()->requireRole('admin')`

## Setup

Auth requires configuration. Create `_config/mini/Auth/AuthInterface.php`:

```php
<?php
// _config/mini/Auth/AuthInterface.php

return new App\Auth\SessionAuth();
```

### Implementing AuthInterface

```php
<?php
namespace App\Auth;

use mini\Auth\AuthInterface;

class SessionAuth implements AuthInterface
{
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public function getUserId(): mixed
    {
        return $_SESSION['user_id'] ?? null;
    }

    public function getClaim(string $name): mixed
    {
        return $_SESSION['user'][$name] ?? null;
    }

    public function hasRole(string $role): bool
    {
        $userRoles = $_SESSION['user']['roles'] ?? [];
        return in_array($role, $userRoles);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $_SESSION['user']['permissions'] ?? [];
        return in_array($permission, $permissions);
    }
}
```

## Common Usage Examples

### Basic Authentication Check

```php
// Check if user is logged in
if (auth()->isAuthenticated()) {
    echo "Welcome back!";
}

// Get current user ID
$userId = auth()->getUserId();

// Get user claims
$email = auth()->getClaim('email');
$name = auth()->getClaim('name');
```

### Route Protection

```php
<?php
// _routes/admin/dashboard.php

// Require login - throws AuthenticationRequiredException (→ 401) if not
// authenticated. The dispatcher converts it and renders _views/errors/401.php
auth()->requireLogin();

// Or require specific role - throws AccessDeniedException (→ 403) if missing
auth()->requireRole('admin');

// Or require permission
auth()->requirePermission('edit_posts');

// Chain requirements
auth()->requireLogin()
      ->requireRole('editor')
      ->requirePermission('publish_posts');

// Route files must return a handler or response - never echo
return fn() => new mini\Http\Message\HtmlResponse(
    mini\render('admin/dashboard', ['data' => loadDashboardData()])
);
```

### Role-Based Access

```php
// Check roles
if (auth()->hasRole('admin')) {
    // Show admin panel
}

if (auth()->hasRole('editor') || auth()->hasRole('admin')) {
    // Show editor tools
}
```

### Permission-Based Access

```php
// Check permissions
if (auth()->hasPermission('delete_users')) {
    echo '<button>Delete User</button>';
}

if (auth()->hasPermission('edit_posts')) {
    // Show edit button
}
```

### Login/Logout Flow

```php
<?php
// _routes/login.php

use mini\Mini;
use mini\Http\Message\Response;
use mini\Session\SessionInterface;

return function (): Response {
    $user = db()->queryOne("SELECT * FROM users WHERE email = ?", [$_POST['email'] ?? '']);

    if (!$user || !password_verify($_POST['password'] ?? '', $user->password_hash)) {
        return new Response('Invalid credentials', [], 401);
    }

    $_SESSION['user_id'] = $user->id;
    $_SESSION['user'] = [
        'email' => $user->email,
        'name' => $user->name,
        'roles' => explode(',', $user->roles),
        'permissions' => explode(',', $user->permissions),
    ];

    // Prevent session fixation
    Mini::$mini->get(SessionInterface::class)->regenerate(deleteOldSession: true);

    return new Response('', ['Location' => '/dashboard'], 303);
};
```

```php
<?php
// _routes/logout.php

use mini\Mini;
use mini\Http\Message\Response;
use mini\Session\SessionInterface;

return function (): Response {
    Mini::$mini->get(SessionInterface::class)->destroy();
    return new Response('', ['Location' => '/'], 303);
};
```

### API Route Protection

```php
<?php
// _routes/api/protected.php

// Framework converts the exception to a 401 response
auth()->requireLogin();

// Protected API logic
return fn() => ['data' => 'secret'];  // → JSON response
```

### Custom Error Pages

Error pages are templates. Create `_views/errors/401.php` for unauthenticated access and `_views/errors/403.php` for forbidden access — they receive `$message` and `$exception` and are rendered through the template system (see `mini\Http\ErrorHandler`):

```php
<?php // _views/errors/401.php ?>
<h1><?= mini\h(mini\t("Please log in")) ?></h1>
<p><?= mini\h($message) ?></p>
```

To respond differently — e.g. redirect to a login page, or emit JSON for an API — register an exception converter on the dispatcher instead:

```php
use mini\Exceptions\AuthenticationRequiredException;
use mini\Http\Message\Response;

$dispatcher->registerExceptionConverter(function(AuthenticationRequiredException $e): Response {
    $returnTo = urlencode(request()->getRequestTarget());
    return new Response('', ['Location' => "/login/?returnTo=$returnTo"], 303);
});
```

## Direct Implementation Access

When you need more than the facade provides:

```php
$authImpl = auth()->getImplementation();

// Call custom methods on your implementation
if (method_exists($authImpl, 'refreshToken')) {
    $authImpl->refreshToken();
}
```

## Advanced: JWT Authentication

```php
<?php
// _config/mini/Auth/AuthInterface.php

return new App\Auth\JWTAuth($_ENV['JWT_SECRET']);
```

```php
<?php
namespace App\Auth;

use mini\Auth\AuthInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTAuth implements AuthInterface
{
    private ?array $claims = null;

    public function __construct(private string $secret) {}

    private function getClaims(): ?array
    {
        if ($this->claims !== null) {
            return $this->claims;
        }

        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);

        if (!$token) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            $this->claims = (array) $decoded;
            return $this->claims;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->getClaims() !== null;
    }

    public function getUserId(): mixed
    {
        return $this->getClaim('sub');
    }

    public function getClaim(string $name): mixed
    {
        return $this->getClaims()[$name] ?? null;
    }

    public function hasRole(string $role): bool
    {
        $roles = $this->getClaim('roles') ?? [];
        return in_array($role, $roles);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getClaim('permissions') ?? [];
        return in_array($permission, $permissions);
    }
}
```

## Configuration

**Config File:** `_config/mini/Auth/AuthInterface.php` (required)

**Environment Variables:** None - auth is entirely custom

## Overriding the Service

Auth uses two services:
1. **`mini\Auth\Auth`** (facade) - Automatically registered as Singleton
2. **`mini\Auth\AuthInterface`** (your implementation) - You provide via config

To use a different facade (advanced):

```php
// _config/mini/Auth/Auth.php
return new App\Auth\CustomAuthFacade();
```

## Error Handling

When auth requirements aren't met, Mini throws:

- **`mini\Exceptions\AuthenticationRequiredException`** → **401 Unauthorized** - user not authenticated (`requireLogin()`)
- **`mini\Exceptions\AccessDeniedException`** → **403 Forbidden** - authenticated but lacks the role/permission (`requireRole()`, `requirePermission()`)

The dispatcher's built-in exception converters map these to 401/403 responses, rendered via `_views/errors/{statusCode}.php` (application) with a framework default as fallback. Register your own converter to override (see above).
