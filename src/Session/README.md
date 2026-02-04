# Session - Fiber-Safe Session Management

Mini provides a cache-backed session implementation that works transparently with `$_SESSION` while being fully fiber-safe and PSR-7 compatible.

## Usage

Just use `$_SESSION` as you normally would in PHP:

```php
// Store data
$_SESSION['user_id'] = 123;
$_SESSION['cart'] = ['item1', 'item2'];

// Read data
$userId = $_SESSION['user_id'];

// Check existence
if (isset($_SESSION['user_id'])) {
    // User is logged in
}

// Remove data
unset($_SESSION['flash_message']);

// Iterate
foreach ($_SESSION as $key => $value) {
    echo "$key: $value\n";
}
```

That's it. No `session_start()` needed. Sessions auto-start on first access and auto-save on write.

## Why This Works

Mini replaces `$_SESSION` with a proxy object during request handling. This proxy:

- **Auto-starts** the session on first read/write
- **Saves immediately** on every write (no data loss on crashes)
- **Is fiber-safe** - each concurrent request gets its own session instance
- **Works with PSR-7** - reads cookies from `ServerRequestInterface`, sets cookies via PSR-7 response headers

You can safely use `$_SESSION` even in Swoole, ReactPHP, or phasync environments where multiple requests run concurrently.

## Direct Interface Access

For type-hinted code or when you need the full interface:

```php
use mini\Session\SessionInterface;

$session = \mini\Mini::$mini->get(SessionInterface::class);

// Same operations as $_SESSION
$session->set('user_id', 123);
$userId = $session->get('user_id');
$session->remove('temp_data');

// Additional methods
$sessionId = $session->getId();
$allData = $session->all();
$session->clear();  // Remove all data

// Security: regenerate ID after login
$session->regenerate(deleteOldSession: true);

// Destroy session completely (logout)
$session->destroy();
```

## Session Storage

Sessions are stored in Mini's cache backend (APCu, SQLite, or filesystem). Session data persists across requests and server restarts (when using SQLite or filesystem cache).

The default TTL is 180 minutes (3 hours), matching PHP's `session.cache_expire` setting. The TTL is refreshed on every write operation.

---

## Configuration

Mini's session respects standard PHP session configuration directives. Configure via `php.ini` or during application bootstrap.

### Cookie Name

```ini
; php.ini
session.name = MY_APP_SESSION
```

Or in bootstrap code:
```php
session_name('MY_APP_SESSION');
```

### Cache Lifetime (Server-Side)

How long session data is stored in cache:

```ini
; php.ini (value in minutes, default: 180)
session.cache_expire = 1440  ; 24 hours
```

Or: `session_cache_expire(1440);`

### Cookie Lifetime (Client-Side)

How long the cookie persists in the browser:

```ini
; php.ini (value in seconds, default: 0 = session cookie)
session.cookie_lifetime = 86400  ; 24 hours
```

When `cookie_lifetime = 0`, the cookie is deleted when the browser closes (session cookie). Mini still sets an expiry based on `cache_expire` to ensure the cookie outlives typical browser sessions.

### Cookie Attributes

All cookie attributes are read from `session_get_cookie_params()` with secure fallbacks:

```ini
; php.ini
session.cookie_path = /
session.cookie_domain = .example.com  ; Optional, for subdomain sharing
session.cookie_secure = 1             ; Require HTTPS
session.cookie_httponly = 1           ; Prevent JavaScript access
session.cookie_samesite = Lax         ; CSRF protection
```

| Attribute | php.ini Setting | Default | Secure Fallback |
|-----------|-----------------|---------|-----------------|
| `Path` | `session.cookie_path` | `/` | `/` |
| `Domain` | `session.cookie_domain` | (empty) | (omitted) |
| `Secure` | `session.cookie_secure` | `0` | Auto-detect HTTPS |
| `HttpOnly` | `session.cookie_httponly` | (empty) | `true` |
| `SameSite` | `session.cookie_samesite` | (empty) | `Lax` |

> **Note:** PHP's compiled-in defaults are insecure (`httponly=false`, `samesite=''`). Mini applies secure fallbacks when these aren't explicitly configured in php.ini.

### Strict Mode (Session Fixation Protection)

When enabled, Mini validates that session IDs exist in cache before accepting them. Unknown IDs are rejected and new sessions are created:

```ini
; php.ini
session.use_strict_mode = 1
```

This prevents session fixation attacks where an attacker tricks a user into using a predetermined session ID.

### Cache Limiter (Prevent CDN/Proxy Caching)

When a session cookie is set, Mini adds cache control headers to prevent CDNs and proxies from caching personalized responses:

```ini
; php.ini (default: nocache)
session.cache_limiter = nocache
```

| Mode | Headers | Use Case |
|------|---------|----------|
| `nocache` | `Cache-Control: no-store, no-cache, must-revalidate` | Default, safest |
| `private` | `Cache-Control: private, max-age=<cache_expire>` | Browser-only caching |
| `public` | `Cache-Control: public, max-age=<cache_expire>` | CDN caching (careful!) |
| `private_no_expire` | `Cache-Control: private` | Browser cache, no expiry |
| `none` | (no headers added) | App handles caching |

> **Note:** Cache headers are always applied when a session cookie is set. If the response already has `Cache-Control`, it will be made *more* restrictive (never less). Caching a response with a session cookie is always a bug - it could leak personalized data to other users.

### Configuration Summary

| Setting | Function | Default | Purpose |
|---------|----------|---------|---------|
| `session.name` | `session_name()` | `PHPSESSID` | Cookie name |
| `session.cache_expire` | `session_cache_expire()` | `180` (min) | Server-side TTL |
| `session.cookie_lifetime` | `session_get_cookie_params()` | `0` | Client-side TTL |
| `session.cookie_*` | `session_get_cookie_params()` | (varies) | Cookie attributes |
| `session.use_strict_mode` | `ini_get()` | `0` | Fixation protection |
| `session.cache_limiter` | `ini_get()` | `nocache` | Response cache policy |

## Architecture

### How It Works

1. **Request arrives** → `HttpDispatcher` creates PSR-7 `ServerRequest` with cookies
2. **`$_SESSION` accessed** → Proxy delegates to `Session` service (scoped per-request)
3. **Session reads cookie** → Gets session ID from `ServerRequest::getCookieParams()`
4. **Data stored in cache** → Uses `CacheInterface` (APCu/SQLite/filesystem)
5. **Response sent** → `SessionMiddleware` adds `Set-Cookie` header if needed

### PSR-7 Integration

Unlike PHP's native sessions which use `setcookie()`, Mini's sessions integrate with PSR-7:

- **Reads** cookies from `ServerRequestInterface::getCookieParams()`
- **Writes** cookies via `Set-Cookie` response header (PSR-15 middleware)

This means sessions work correctly in:
- Traditional PHP-FPM
- Swoole
- ReactPHP
- phasync
- Any PSR-15 compatible runtime

### Files

| File | Purpose |
|------|---------|
| `SessionInterface.php` | Public API contract |
| `Session.php` | Cache-backed implementation |
| `SessionProxy.php` | `$_SESSION` replacement (ArrayAccess) |
| `SessionMiddleware.php` | PSR-15 middleware for cookie handling |
| `functions.php` | Service registration |

## Security Considerations

### Session Fixation

Regenerate the session ID after authentication state changes:

```php
// After successful login
$_SESSION['user_id'] = $user->id;
\mini\Mini::$mini->get(SessionInterface::class)->regenerate(deleteOldSession: true);
```

### Session Hijacking

The `HttpOnly` and `Secure` cookie flags help prevent session hijacking:
- `HttpOnly` prevents JavaScript from reading the session cookie
- `Secure` ensures the cookie is only sent over HTTPS

### Logout

Always destroy the session on logout:

```php
\mini\Mini::$mini->get(SessionInterface::class)->destroy();
```

This clears all session data and expires the cookie.
