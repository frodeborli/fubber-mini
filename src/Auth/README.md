# Auth - Authentication System

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

// Require login - throws AccessDeniedException if not authenticated
// Framework catches exception and shows _errors/401.php
auth()->requireLogin();

// Or require specific role
auth()->requireRole('admin');

// Or require permission
auth()->requirePermission('edit_posts');

// Chain requirements
auth()->requireLogin()
      ->requireRole('editor')
      ->requirePermission('publish_posts');

// Continue with protected logic
$dashboard = loadDashboardData();
echo render('admin/dashboard', ['data' => $dashboard]);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = db()->queryOne(
        "SELECT * FROM users WHERE email = ? AND password = ?",
        [$_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT)]
    );

    if ($user) {
        session();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'email' => $user['email'],
            'name' => $user['name'],
            'roles' => explode(',', $user['roles']),
            'permissions' => explode(',', $user['permissions'])
        ];

        redirect('/dashboard');
    }
}
```

```php
<?php
// _routes/logout.php

session();
session_destroy();
redirect('/');
```

### API Route Protection

```php
<?php
// _routes/api/protected.php

// Framework will catch exception and show _errors/401.php
auth()->requireLogin();

// Protected API logic
header('Content-Type: application/json');
echo json_encode(['data' => $secretData]);
```

### Custom Error Pages

Create `_errors/401.php` for unauthorized access:

```php
<?php
// _errors/401.php

header('Content-Type: application/json');
echo json_encode([
    'error' => 'Unauthorized',
    'message' => 'Please log in to access this resource'
]);
```

Create `_errors/403.php` for forbidden access:

```php
<?php
// _errors/403.php

header('Content-Type: application/json');
echo json_encode([
    'error' => 'Forbidden',
    'message' => 'You do not have permission to access this resource'
]);
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

When auth requirements aren't met, Mini throws `\mini\Http\AccessDeniedException`:

- **401 Unauthorized** - User not authenticated
- **403 Forbidden** - User authenticated but lacks permission/role

The framework automatically handles these exceptions and shows appropriate error pages.
