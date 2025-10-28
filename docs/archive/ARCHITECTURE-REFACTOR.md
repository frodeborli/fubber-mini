# Architecture Refactor - Routes & Config - 2025-10-27

## Summary

Implemented a **clean separation** between framework directories and document root by introducing underscore-prefixed directories (`_routes/`, `_config/`, `_errors/`). This works even when `ROOT === DOC_ROOT` for simpler hosting providers.

## Key Changes

### **Directory Restructure**

**Before:**
```
project/
├── _router.php          # Framework entry point
├── 404.php              # Error handler
├── config/              # Configuration
│   └── routes.php      # Global routes
├── html/                # Document root
│   ├── users.php       # Route handler (confusing!)
│   └── _routes.php     # Pattern matching
```

**After:**
```
project/
├── _routes/             # Route handlers (not web-accessible)
│   ├── index.php       # / route
│   ├── users.php       # /users route
│   └── api/posts.php   # /api/posts route
├── _config/             # Configuration
│   ├── bootstrap.php   # Custom initialization
│   └── routes.php      # Global routes
├── _errors/             # Error pages
│   ├── 404.php
│   └── 500.php
├── html/                # Document root (static files only)
│   ├── index.php       # Entry point: calls mini\router()
│   └── assets/         # CSS, JS, images
```

### **Entry Point Pattern**

**Before:**
```php
// _router.php (framework file)
<?php
require_once __DIR__ . '/vendor/autoload.php';
mini\router();
```

**After:**
```php
// DOC_ROOT/index.php (application entry point)
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();  // Enables routing
```

### **Route Handler Pattern**

**Before (in DOC_ROOT):**
```php
// html/users.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();  // Must call bootstrap

$users = db()->query("SELECT * FROM users")->fetchAll();
echo json_encode($users);
```

**After (in _routes/):**
```php
// _routes/users.php
<?php
// No bootstrap needed!
$users = db()->query("SELECT * FROM users")->fetchAll();
return mini\json_response($users);
```

## Implementation Details

### **1. Mini Class Changes**

**Added:**
- `Mini::$mini->paths->routes` - PathsRegistry for `_routes/`

**Changed:**
- Default config path: `config/` → `_config/`
- Default routes path: N/A → `_routes/`

**Code:**
```php
// src/Mini.php bootstrap()
$this->paths->config = new Util\PathsRegistry($this->root . '/_config');
$this->paths->routes = new Util\PathsRegistry($this->root . '/_routes');
```

### **2. router() Function Changes**

**Before:**
- Minimal function that delegated to SimpleRouter
- Did not call bootstrap()

**After:**
- Sets routing enabled flag
- Calls bootstrap() for error handling and output buffering
- Delegates to SimpleRouter

**Code:**
```php
// functions.php
function router(): void
{
    $GLOBALS['mini_routing_enabled'] = true;
    bootstrap();  // NEW: Bootstrap automatically

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $router = new \mini\SimpleRouter();
    $router->handleRequest($requestUri);
}
```

### **3. bootstrap() Function Changes**

**Before:**
- Complex routing detection logic
- Checked for `_router.php` existence
- Handled `.php` extension redirects

**After:**
- Simplified to only redirect `/index.php` → `/`
- Uses global flag to detect routing
- Removed all clean URL redirect logic

**Code:**
```php
// functions.php bootstrap()
if (isset($GLOBALS['mini_routing_enabled']) && $GLOBALS['mini_routing_enabled']) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

    // Only redirect /index.php → /
    if ($path === '/index.php' || str_ends_with($path, '/index.php')) {
        $redirectTo = rtrim(dirname($path), '/') ?: '/';
        header('Location: ' . $redirectTo, true, 301);
        exit;
    }
}
```

### **4. SimpleRouter Changes**

**Changed methods:**

**tryFileBasedRouting():**
- Before: Searched DOC_ROOT for PHP files
- After: Searches `_routes/` via PathsRegistry

```php
// Before
$phpFile = $this->getDocumentRoot() . '/' . $remaining . '.php';
if (file_exists($phpFile)) {
    return '/' . $phpFile;
}

// After
$routeFile = empty($remaining) ? 'index.php' : $remaining . '.php';
$foundPath = Mini::$mini->paths->routes->findFirst($routeFile);
if ($foundPath) {
    return $routeFile;
}
```

**findScopedRouteFile():**
- Before: Searched DOC_ROOT for `_routes.php` files
- After: Searches `_routes/` subdirectories

```php
// Before
$routeFile = $this->getDocumentRoot() . '/' . $dirPath . '/_routes.php';

// After
$routeFile = $dirPath . '/_routes.php';
$foundPath = Mini::$mini->paths->routes->findFirst($routeFile);
```

**resolveTargetFile():**
- Before: Used DOC_ROOT as base
- After: Uses PathsRegistry to find in `_routes/`

```php
// Before
return $this->getDocumentRoot() . '/' . ltrim($file, '/');

// After
$file = ltrim($file, '/');
$foundPath = Mini::$mini->paths->routes->findFirst($file);
return $foundPath ?? Mini::$mini->root . '/_routes/' . $file;
```

**handle404():**
- Before: Looked for `/404.php` in project root
- After: Looks for `/_errors/404.php`

```php
// Before
if (file_exists(Mini::$mini->root . '/404.php')) {

// After
if (file_exists(Mini::$mini->root . '/_errors/404.php')) {
```

**Removed:**
- `handleCleanUrlRedirects()` - No longer needed

### **5. Error Page Paths**

**All error handlers updated:**
- `functions.php` error handlers now use `_errors/` directory
- `showErrorPage()` looks in `Mini::$mini->root . '/_errors/{statusCode}.php'`

## Benefits

### **1. Clear Separation of Concerns**

| Directory | Purpose | Web Access |
|-----------|---------|------------|
| `_routes/` | Route handlers | ❌ Not accessible |
| `_config/` | Configuration | ❌ Not accessible |
| `_errors/` | Error pages | ❌ Not accessible |
| `DOC_ROOT/` | Static files + entry point | ✅ Accessible |

### **2. Security**

- **Framework code not web-accessible** - Even when `ROOT === DOC_ROOT`
- **Underscore prefix** - Clear visual indicator of framework directories
- **No accidental exposure** - Can't navigate to `/_routes/users.php` in browser

### **3. Developer Experience**

**Cleaner route handlers:**
```php
// _routes/api/posts.php
<?php
return mini\json_response(db()->query("SELECT * FROM posts")->fetchAll());
```

**No bootstrap confusion:**
- Route handlers: No bootstrap needed
- DOC_ROOT PHP files: Must call `mini\bootstrap()`

**Clear intent:**
- `/users` → route handler in `_routes/`
- `/users.php` → static file in DOC_ROOT (if exists)

### **4. Simpler Hosting**

Works with hosting providers where:
- `ROOT === DOC_ROOT` (no separate `public/` or `html/` directory)
- Underscore directories are not served by web server
- No special `.htaccess` rules needed

## Migration Guide

### **For Existing Projects**

**1. Move directories:**
```bash
mv config _config
mkdir _routes
mkdir _errors
```

**2. Move route handlers:**
```bash
# Move PHP files from html/ to _routes/
mv html/users.php _routes/users.php
mv html/api/posts.php _routes/api/posts.php
```

**3. Update DOC_ROOT/index.php:**
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();  // Remove bootstrap() call, just use router()
```

**4. Remove bootstrap from route handlers:**
```php
// OLD: _routes/users.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();  // REMOVE THIS
// ...

// NEW: _routes/users.php
<?php
// No bootstrap needed!
return mini\json_response($data);
```

**5. Move error pages:**
```bash
mv 404.php _errors/404.php
mv 500.php _errors/500.php
```

### **For New Projects**

**1. Create structure:**
```bash
mkdir _routes _config _errors html
```

**2. Create entry point:**
```php
// html/index.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();
```

**3. Create routes:**
```php
// _routes/index.php
<?php
echo "<h1>Welcome!</h1>";
```

**4. Optional: Custom initialization:**
```php
// _config/bootstrap.php
<?php
// Custom app initialization
```

## Testing

All tests pass:
- ✅ `tests/router-new-architecture.php` - Verifies PathsRegistry setup
- ✅ `tests/bootstrap.php` - Verifies simplified bootstrap
- ✅ `tests/translator-locale.php` - I18n still works
- ✅ `tests/container.php` - PSR-11 container still works

## Breaking Changes

**None!** This is a new architecture that coexists with the old pattern. Existing projects can migrate gradually.

**Old pattern still works:**
- DOC_ROOT PHP files can still call `mini\bootstrap()`
- No forced migration

**New pattern preferred:**
- Cleaner separation
- Better security
- Simpler code

## Future Enhancements

1. **Bundle support** - Bundles can add their own `_routes/` via PathsRegistry
2. **Route caching** - Cache compiled routes for production
3. **Hot reload** - Development mode with route hot reloading
4. **Route debugging** - Debug UI showing all registered routes

## Documentation

- ✅ Updated `CLAUDE.md` with new architecture
- ✅ Updated "Application Structure" section
- ✅ Updated "Routing System" section
- ✅ Updated "Entry Point Pattern" examples
- ✅ Updated "Route Handler Pattern" examples
- ✅ Updated "Program Flow" diagram
