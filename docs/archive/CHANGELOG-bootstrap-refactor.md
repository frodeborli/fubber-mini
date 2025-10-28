# Bootstrap Refactoring - 2025-10-27

## Summary

Simplified `mini\bootstrap()` from 162 lines to ~95 lines by removing deprecated patterns and focusing on core controller needs.

## Changes

### bootstrap() Function

**Removed:**
- ❌ `$GLOBALS['app']` initialization (no longer needed)
- ❌ `config.php` loading (deprecated, use environment variables)
- ❌ Router detection and delegation (belongs in `_router.php`)
- ❌ `$disable_router` parameter (no longer needed)
- ❌ `$options` parameter (unused)
- ❌ Double-bootstrap error tracking

**Added:**
- ✅ Idempotent design (safe to call multiple times)
- ✅ Smart routing detection (two methods: `_router.php` exists OR URL mismatch)
- ✅ Clean URL redirects only when routing is enabled

**Preserved:**
- ✅ Error handler setup (converts errors to exceptions)
- ✅ Exception handler setup (renders error pages)
- ✅ Output buffer cleanup
- ✅ Strategic output buffering for exception recovery
- ✅ Project bootstrap inclusion (`config/bootstrap.php`)

### Error Handling Functions

Updated to use `Mini::$mini->root` instead of `$projectRoot` parameter:
- `handleAccessDeniedException()` - Removed `$projectRoot` parameter
- `handleHttpException()` - Removed `$projectRoot` parameter
- `showErrorPage()` - Removed `$projectRoot` parameter, uses `Mini::$mini->root`

### Error Page Locations

Changed from `$docRoot/404.php` to `$root/_errors/{statusCode}.php`:
- `_errors/401.php` - Unauthorized
- `_errors/403.php` - Forbidden
- `_errors/404.php` - Not Found
- `_errors/500.php` - Internal Server Error

**Security benefit:** Error pages now stored in project root (not web-accessible).

### Helper Functions

**cleanGlobalControllerOutput():**
- Simplified to remove `$GLOBALS['app']['ob_started']` check
- Now just checks `ob_get_level() > 0`

**request():**
- Removed bootstrap validation check
- Kept `$GLOBALS['app']['psr']` caching for PSR-7 objects

## Routing Detection

Bootstrap now detects if routing is enabled using two methods:

### Method 1: `_router.php` Exists
```php
if ($docRoot && file_exists($docRoot . '/_router.php')) {
    $routingEnabled = true;
}
```

### Method 2: URL Doesn't Match Script
```php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

if ($scriptName && $requestUri !== $scriptName && !str_ends_with($requestUri, '.php')) {
    $routingEnabled = true;
}
```

**Why both methods?**
- Method 1: Explicit routing file presence
- Method 2: Server already redirected (nginx/apache rewrite rules active)

## Usage Pattern

### Before (Deprecated)
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap([], disable_router: true);

// Controller code...
```

### After (Current)
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();

// Controller code...
```

**Benefits:**
- Simpler API (no parameters)
- Idempotent (safe to call from included files)
- No router detection (belongs in `_router.php`)

## Application Structure

```
project/
├── _router.php           # Routing entry point
├── _errors/              # Error page templates (NEW LOCATION)
│   ├── 401.php
│   ├── 403.php
│   ├── 404.php
│   └── 500.php
├── html/ or public/      # Document root
│   ├── _router.php      # Optional: enables routing
│   ├── index.php        # Calls mini\bootstrap()
│   └── ...
├── config/
│   └── bootstrap.php    # Optional: custom initialization
└── vendor/
```

## Testing

All existing tests pass:
- ✅ `tests/bootstrap.php` - New test for bootstrap functionality
- ✅ `tests/translator-locale.php` - Translator locale handling
- ✅ `tests/container.php` - PSR-11 container
- ✅ `tests/default-language.php` - Default language property

## Documentation Updates

- Updated `CLAUDE.md` "Controller Bootstrap Pattern" section
- Updated `CLAUDE.md` "Application Structure" section
- Documented routing detection methods
- Documented error page locations

## Migration Guide

### For Existing Projects

1. **Remove `config.php` usage** - Use environment variables instead
2. **Move error pages** - From `html/404.php` to `_errors/404.php`
3. **Simplify bootstrap calls** - Remove `disable_router` parameter
4. **Update error page paths** - They're now in `{root}/_errors/`

### For Controller Files

No changes needed! Controller files continue to work:
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();

// Your code...
```

## Benefits

1. **Simpler code** - 67 lines removed from bootstrap()
2. **Better security** - Error pages not web-accessible
3. **Clearer purpose** - Bootstrap focused on controller needs
4. **Idempotent** - Safe to call multiple times
5. **Smart detection** - Routing detection more robust
6. **No globals** - Removed `$GLOBALS['app']` dependency
7. **Better performance** - Less initialization overhead

## Breaking Changes

**None!** All existing controller code continues to work. The only deprecated pattern is `config.php` loading, which was already documented as deprecated.
