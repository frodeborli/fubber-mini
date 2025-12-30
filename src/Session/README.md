# Mini Session

Auto-starting session management that works transparently in both traditional PHP and async environments.

## Key Features

- **Auto-start on access** - No need to call `session_start()`, sessions start automatically when you access `$_SESSION`
- **Auto-save on request end** - Sessions are automatically saved via the `HttpDispatcher::onAfterRequest` hook
- **Fiber-safe** - Uses load-into-memory pattern for async environments (phasync, Swoole)
- **Drop-in replacement** - Existing `$_SESSION` code works unchanged

## Basic Usage

Just use `$_SESSION` as you normally would - it auto-starts:

```php
// Set values - session starts automatically
$_SESSION['user_id'] = 123;
$_SESSION['username'] = 'john';

// Get values
$userId = $_SESSION['user_id'];

// Check existence
if (isset($_SESSION['user_id'])) {
    // User is logged in
}

// Remove values
unset($_SESSION['user_id']);

// Iterate
foreach ($_SESSION as $key => $value) {
    echo "$key: $value\n";
}
```

## Using the session() Helper

For more control, use the `session()` helper function:

```php
use function mini\session;

// Set/get with methods
session()->set('user_id', 123);
$userId = session()->get('user_id');
$username = session()->get('username', 'guest');  // With default

// Check and remove
if (session()->has('user_id')) {
    session()->remove('user_id');
}

// Get all data
$allData = session()->all();

// Clear everything
session()->clear();

// Get session ID
$sessionId = session()->getId();

// Regenerate ID (e.g., after login for security)
session()->regenerate(deleteOldSession: true);

// Destroy session completely (e.g., on logout)
session()->destroy();
```

## Manual Save

Sessions auto-save at request end, but you can save early to release the session lock:

```php
// After setting session data
$_SESSION['user_id'] = 123;

// Save now to release lock
session()->save();

// Long-running operation that doesn't need session...
processLargeFile();
```

## How It Works

### Architecture

```
$_SESSION (SessionProxy)
    ↓
SessionInterface (per-request via Lifetime::Scoped)
    ↓
Session (native PHP session handling)
```

1. **SessionProxy** - Global `$_SESSION` replacement that delegates to the Session service
2. **SessionInterface** - Service contract, registered with `Lifetime::Scoped` for per-request instances
3. **Session** - Implementation using PHP's native session functions

### Auto-Start Behavior

When you access `$_SESSION['key']`, the SessionProxy:
1. Gets the Session instance from Mini's container
2. Session starts PHP's native session if not already started
3. Loads session data into memory
4. Returns/sets the requested value

### Auto-Save Behavior

The Session module subscribes to `HttpDispatcher::onAfterRequest`:
```php
$dispatcher->onAfterRequest->listen(function() {
    $session = Mini::$mini->get(SessionInterface::class);
    if ($session->isStarted()) {
        $session->save();
    }
});
```

This ensures sessions are saved even if:
- An exception was thrown
- Response was already sent via `echo`/`header()`

### Fiber/Async Environment Handling

In async environments (detected via `Fiber::getCurrent()`), the Session:
1. Starts native session and loads data into memory
2. Immediately calls `session_write_close()` to release the lock
3. Keeps data in memory, tracks modifications
4. Saves back to storage at request end

This prevents session lock contention when multiple fibers/coroutines run concurrently.

## Configuration

### Session Name and Cookie

Use PHP's native configuration:

```php
// In bootstrap.php
ini_set('session.name', 'MYAPP_SESSION');
ini_set('session.cookie_lifetime', 86400);  // 1 day
ini_set('session.cookie_secure', '1');      // HTTPS only
ini_set('session.cookie_httponly', '1');    // No JavaScript access
ini_set('session.cookie_samesite', 'Lax');  // CSRF protection
```

### Custom Session Handler

Use PHP's `session_set_save_handler()` with any `SessionHandlerInterface`:

```php
// In bootstrap.php
session_set_save_handler(new RedisSessionHandler($redis), true);
```

Or extend `SessionHandler` to intercept read/write (e.g., for encryption):

```php
class EncryptedSessionHandler extends SessionHandler
{
    public function read(string $id): string|false
    {
        $data = parent::read($id);
        return $data ? decrypt($data) : '';
    }

    public function write(string $id, string $data): bool
    {
        return parent::write($id, encrypt($data));
    }
}

session_set_save_handler(new EncryptedSessionHandler(), true);
```

## Best Practices

### 1. Regenerate Session ID After Login

```php
// After successful authentication
if (auth()->login($username, $password)) {
    session()->regenerate(deleteOldSession: true);
    $_SESSION['user_id'] = auth()->getUserId();
    redirect('/dashboard');
}
```

### 2. Destroy Session on Logout

```php
function logout(): void
{
    session()->destroy();
    redirect('/login');
}
```

### 3. Release Lock Early for Long Operations

```php
// Set what you need
$_SESSION['last_activity'] = time();

// Release lock before slow operation
session()->save();

// Now do slow stuff without blocking other requests
generateReport();
```

### 4. Check Session Status

```php
if (session()->isStarted()) {
    // Session is active
}
```

## File Structure

```
src/Session/
├── SessionInterface.php  # Service contract
├── Session.php           # Native PHP session implementation
├── SessionProxy.php      # $_SESSION global replacement
├── functions.php         # session() helper + service registration
└── README.md             # This file
```

## Integration with HttpDispatcher

The Session module uses HttpDispatcher's lifecycle hooks:

- **`onAfterRequest`** - Auto-saves session data after each request

These hooks ensure proper session handling regardless of how the request completes (success, exception, or early return).
