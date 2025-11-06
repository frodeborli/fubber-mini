# Auth - Authentication

Manage user authentication with `mini\auth()`, `mini\is_logged_in()`, and `mini\require_login()`.

## Setup

```php
<?php
// Configure authentication (once, in bootstrap or entry point)
mini\setupAuth(
    loginPath: '/login',
    logoutPath: '/logout',
    loginHandler: function($username, $password) {
        $user = db()->query(
            "SELECT * FROM users WHERE username = ? AND password_hash = ?",
            [$username, hash('sha256', $password)]
        )->fetch();

        return $user ?: null;
    }
);
```

## Login

```php
<?php
// _routes/login.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (mini\auth()->login($_POST['username'], $_POST['password'])) {
        mini\redirect('/dashboard');
    } else {
        echo "Invalid credentials";
    }
}
```

## Logout

```php
<?php
// _routes/logout.php
mini\auth()->logout();
mini\redirect('/');
```

## Check Login Status

```php
<?php
if (mini\is_logged_in()) {
    $user = mini\auth()->getUser();
    echo "Welcome, {$user['username']}!";
}
```

## Require Login

```php
<?php
// _routes/dashboard.php
mini\require_login();  // Redirects to login if not authenticated

echo "Welcome to your dashboard!";
```

## Require Specific Role

```php
<?php
// _routes/admin.php
mini\require_role('admin');  // Throws AccessDeniedException if unauthorized

echo "Admin panel";
```

## Custom User Provider

Override authentication via `_config/mini/Auth/AuthService.php`:

```php
<?php
return new MyCustomAuthService();
```

## API Reference

See `mini\Auth\AuthService` for full authentication API.
