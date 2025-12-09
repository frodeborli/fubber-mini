# Static - Static File Serving

PSR-15 middleware for serving static files from `_static/` directories with caching support.

## Quick Start

Enable static file serving by requiring the bootstrap:

```php
// In your bootstrap or _config/services.php
require 'vendor/fubber/mini/src/Static/functions.php';
```

Files in `_static/css/style.css` become accessible at `/css/style.css`.

## Directory Structure

```
your-app/
├── _static/              # Your static files (primary)
│   ├── css/
│   ├── js/
│   └── images/
└── vendor/fubber/mini/
    └── _static/          # Framework defaults (fallback)
```

## Resolution Order

Static files are resolved using PathRegistry:

1. **Application**: `_static/` (or `MINI_STATIC_ROOT` env var) - always first (primary path)
2. **Third-party packages**: Their `_static/` directories (most recently added first)
3. **Framework**: `vendor/fubber/mini/_static/` - always last

The application path is set as the primary path in the constructor, so it always has highest priority. Fallback paths added via `addPath()` are inserted after the primary but before any previously added fallbacks.

## Composer Package Integration

Third-party Composer packages can provide static files by calling `addPath()` during bootstrap. Since Composer loads `autoload.files` in dependency graph order (framework → packages → application), and `addPath()` inserts new paths before earlier fallbacks, the resolution order naturally allows packages to override framework assets.

### Creating a Package with Static Files

In your package's `composer.json`:

```json
{
    "name": "vendor/my-package",
    "autoload": {
        "files": ["src/bootstrap.php"]
    }
}
```

In `src/bootstrap.php`:

```php
use mini\Mini;

Mini::$mini->paths->static->addPath(__DIR__ . '/../_static');
```

Your package structure:

```
my-package/
├── _static/
│   └── my-package/
│       ├── css/
│       └── js/
├── src/
│   └── bootstrap.php
└── composer.json
```

Files become accessible at `/my-package/css/...` etc.

### Override Hierarchy

Given this Composer loading order:

1. Framework bootstrap runs first → adds `vendor/fubber/mini/_static/`
2. Package bootstrap runs next → adds its `_static/` (inserted before framework)
3. Application path is already primary (highest priority)

Resolution order: Application → Package → Framework

To override a package's `widget.js`, place your version at the same relative path in your application's `_static/` directory.

## Features

### Caching Headers

All responses include aggressive caching headers:

- `Cache-Control: public, max-age=31536000, immutable` (1 year)
- `ETag` based on file path, modification time, and size
- `Last-Modified` timestamp

### Conditional Requests

Supports `If-None-Match` and `If-Modified-Since` headers, returning `304 Not Modified` when appropriate to save bandwidth.

### MIME Types

MIME types are determined by file extension using `_config/mimeTypes.php`. Unknown extensions default to `application/octet-stream`.

## Configuration

### Custom Static Root

Set `MINI_STATIC_ROOT` environment variable to change the primary static directory:

```bash
MINI_STATIC_ROOT=/var/www/assets
```

### Adding Additional Paths

```php
use mini\Mini;

Mini::$mini->paths->static->addPath('/shared/assets');
```

## Components

### StaticFiles (Middleware)

PSR-15 middleware that intercepts requests and serves matching static files. Non-matching requests pass through to the router.

```php
use mini\Static\StaticFiles;

$middleware = new StaticFiles();

// Find asset path
$path = $middleware->findAsset('css/style.css');

// Get MIME type
$mime = $middleware->getMimeType('script.js');  // 'application/javascript'
```

### functions.php (Bootstrap)

Registers the PathRegistry, middleware service, and adds it to the HttpDispatcher pipeline. Include once during application bootstrap.
