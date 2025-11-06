# Http - Request & Response Utilities

Handle HTTP requests and responses using native PHP with helper functions.

## Request Data

```php
<?php
// GET parameters
$id = $_GET['id'] ?? null;
$search = $_GET['q'] ?? '';

// POST data
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Cookies
$sessionId = $_COOKIE['session_id'] ?? null;

// Session
mini\session();  // Start session if not started
$userId = $_SESSION['user_id'] ?? null;
```

## Responses

```php
<?php
// Redirect
mini\redirect('/login');
mini\redirect('/dashboard', statusCode: 301);

// Current URL
$currentUrl = mini\current_url();

// Generate URL
$url = mini\url('/api/users', ['page' => 2, 'limit' => 10]);
// Result: "/api/users?page=2&limit=10"
```

## Flash Messages

```php
<?php
// Set flash message (survives one redirect)
mini\flash_set('success', 'User created successfully');
mini\redirect('/users');

// Get and clear flash message
$message = mini\flash_get('success');
if ($message) {
    echo "<div class='alert-success'>{$message}</div>";
}
```

## CSRF Protection

```php
<?php
// In form
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= mini\csrf() ?>">
    <!-- form fields -->
</form>

// Validate (automatic via Mini's routing if token present)
// Or manually:
if ($_POST['csrf_token'] !== mini\csrf()) {
    throw new Exception('CSRF token mismatch');
}
```

## Error Pages

```php
<?php
// Show custom error page
mini\showErrorPage(404, 'Page not found');
mini\showErrorPage(500, 'Internal server error');

// Get HTTP status text
echo mini\getHttpStatusText(404);  // "Not Found"
```

## HTML Escaping

```php
<?php
// Escape output
echo mini\h($userInput);  // Prevents XSS

// In templates
<h1><?= mini\h($title) ?></h1>
<p><?= mini\h($description) ?></p>
```

## Response Headers

```php
<?php
// Set content type
header('Content-Type: application/json');
header('Content-Type: text/plain');

// Set status code
http_response_code(201);
http_response_code(404);

// Download file
header('Content-Disposition: attachment; filename="export.csv"');
```
