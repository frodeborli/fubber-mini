# Mini Framework - Common Patterns

This document contains common patterns for extending and customizing Mini framework behavior.

## Table of Contents

- [Overriding Framework Services](#overriding-framework-services)
- [Request Processing (Middleware Pattern)](#request-processing-middleware-pattern)
- [Response Processing (Output Buffering)](#response-processing-output-buffering)

---

## Overriding Framework Services

Mini allows applications to override framework default services using config files.

### How It Works

The framework registers services using `Mini::$mini->loadServiceConfig()`, which:
1. First checks for application config at `_config/[namespace]/[ClassName].php`
2. Falls back to framework default at `vendor/fubber/mini/config/[namespace]/[ClassName].php`

This means **you override services by creating config files**, not by registering them before the framework loads.

### Pattern: Override Services via Config Files

**Example: Custom Logger (Monolog)**

```php
<?php
// _config/Psr/Log/LoggerInterface.php

return new \Monolog\Logger('app', [
    new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Logger::DEBUG),
    new \Monolog\Handler\SentryHandler(/* ... */),
]);
```

**Example: Custom Cache (Redis)**

```php
<?php
// _config/Psr/SimpleCache/CacheInterface.php

return new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter(
        \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection('redis://localhost')
    )
);
```

**Example: Custom Database (PostgreSQL)**

```php
<?php
// _config/PDO.php

return new PDO(
    'pgsql:host=db.example.com;dbname=myapp',
    'user',
    'pass',
    [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => true,
    ]
);
```

**Example: Custom UUID Factory**

```php
<?php
// _config/mini/UUID/FactoryInterface.php

return new \mini\UUID\UUID4Factory();  // Use v4 instead of v7
```

### Config File Lookup

Framework services use this pattern:
```php
Mini::$mini->addService(
    LoggerInterface::class,
    Lifetime::Singleton,
    fn() => Mini::$mini->loadServiceConfig(LoggerInterface::class)
);
```

When you call `log()`, Mini looks for config in this order:
1. `_config/Psr/Log/LoggerInterface.php` (your override)
2. `vendor/fubber/mini/config/Psr/Log/LoggerInterface.php` (framework default)

**Note:** You cannot override framework services by registering them in `app/bootstrap.php` before the framework loads. The framework unconditionally registers its services, and `loadServiceConfig()` handles the override logic via config files.

---

## Request Processing (Middleware Pattern)

Mini doesn't have PSR-15 middleware, but phase lifecycle hooks provide the same capabilities using standard PHP patterns.

### Pattern: Use Phase Transition Hooks

Phase hooks fire when the application transitions to Ready phase (when `mini\bootstrap()` is called). Use `onEnteringState()` for "before request" logic and `onEnteredState()` for "after bootstrap" logic.

**When hooks fire:**
- `onEnteringState(Phase::Ready)` - Before Ready phase transition (before error handlers, output buffering)
- `onEnteredState(Phase::Ready)` - After Ready phase entered (after bootstrap completes)

**Example: Authentication Middleware**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

// Register authentication check for protected routes
// Fires when entering Ready phase (before error handlers set up)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    // Define protected paths
    $protectedPaths = ['/admin', '/api', '/profile'];

    // Check if current path is protected
    foreach ($protectedPaths as $protected) {
        if (str_starts_with($path, $protected)) {
            // Check authentication
            session_start();
            if (!isset($_SESSION['user_id'])) {
                // Not authenticated - return 401
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
            break;
        }
    }
});
```

**Example: Request Logging**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    $start = microtime(true);

    // Log request start
    error_log(sprintf(
        '[%s] %s %s',
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/'
    ));

    // Register shutdown function to log duration
    register_shutdown_function(function() use ($start) {
        $duration = microtime(true) - $start;
        error_log(sprintf('Request completed in %.3f seconds', $duration));
    });
});
```

**Example: CORS Headers**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Add CORS headers for API routes
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (str_starts_with($path, '/api/')) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
});
```

**Example: Request Body Parsing (Extended)**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // Parse XML request bodies
    if (str_contains($contentType, 'application/xml')) {
        $xml = file_get_contents('php://input');
        try {
            $data = simplexml_load_string($xml);
            $_POST = json_decode(json_encode($data), true);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid XML']);
            exit;
        }
    }

    // Parse form-data with file uploads
    if (str_contains($contentType, 'multipart/form-data')) {
        // PHP handles this automatically, but you could add validation here
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        foreach ($_FILES as $file) {
            if ($file['size'] > $maxFileSize) {
                http_response_code(413);
                echo json_encode(['error' => 'File too large']);
                exit;
            }
        }
    }
});
```

### Multiple Hooks = Multiple Middleware

You can register multiple listeners to chain middleware-like logic:

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

// Middleware 1: Rate limiting
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cache = \mini\cache('rate-limit');

    $key = "rate_limit:{$ip}";
    $requests = $cache->get($key, 0);

    if ($requests > 100) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }

    $cache->set($key, $requests + 1, 60); // 100 requests per minute
});

// Middleware 2: Request ID
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    $requestId = bin2hex(random_bytes(8));
    $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
    header("X-Request-ID: {$requestId}");
});

// Middleware 3: Security headers
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
});
```

---

## Response Processing (Output Buffering)

The `onEnteredState(Phase::Ready)` hook fires after the Ready phase is entered, which means:
- Error handlers are configured
- Output buffering has started
- Application is ready to handle requests
- All framework features are available

This is the perfect place for "after bootstrap" processing using PHP's output buffering.

### Pattern: Custom Output Handler

Register a custom output buffer handler after entering Ready phase:

**Example: HTML Minification**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Start a new output buffer with custom handler
    ob_start(function($buffer) {
        // Only minify HTML responses
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = $header;
                break;
            }
        }

        if (str_contains($contentType, 'text/html')) {
            // Simple HTML minification
            $buffer = preg_replace('/\s+/', ' ', $buffer); // Collapse whitespace
            $buffer = preg_replace('/>\s+</', '><', $buffer); // Remove space between tags
        }

        return $buffer;
    });
});
```

**Example: Response Compression**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Use PHP's built-in compression handler
    if (extension_loaded('zlib')) {
        ob_start('ob_gzhandler');
    }
});
```

**Example: Response Time Header**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    $start = microtime(true);

    ob_start(function($buffer) use ($start) {
        $duration = microtime(true) - $start;
        header("X-Response-Time: " . round($duration * 1000, 2) . "ms");
        return $buffer;
    });
});
```

**Example: Inject Analytics Script**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    ob_start(function($buffer) {
        // Only inject into HTML responses
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = $header;
                break;
            }
        }

        if (str_contains($contentType, 'text/html') && str_contains($buffer, '</body>')) {
            $analytics = '<script>/* Google Analytics code here */</script>';
            $buffer = str_replace('</body>', $analytics . '</body>', $buffer);
        }

        return $buffer;
    });
});
```

**Example: Security Headers Injection**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    ob_start(function($buffer) {
        // Inject Content-Security-Policy for HTML pages
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = $header;
                break;
            }
        }

        if (str_contains($contentType, 'text/html')) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'");
        }

        return $buffer;
    });
});
```

### Manipulating Response Headers

Output buffer handlers can manipulate headers before they're sent:

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    ob_start(function($buffer) {
        // Add cache headers based on content
        if (strlen($buffer) > 1024 && !str_contains($buffer, 'user-specific')) {
            header('Cache-Control: public, max-age=3600');
            header('ETag: "' . md5($buffer) . '"');
        } else {
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }

        // Add content length
        header('Content-Length: ' . strlen($buffer));

        return $buffer;
    });
});
```

### Stacking Output Handlers

Multiple output handlers create a processing chain:

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;

Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Handler 1: Minify HTML
    ob_start(function($buffer) {
        if (str_contains(headers_list()[0] ?? '', 'text/html')) {
            $buffer = preg_replace('/\s+/', ' ', $buffer);
        }
        return $buffer;
    });

    // Handler 2: Compress
    ob_start('ob_gzhandler');

    // Handler 3: Add headers
    ob_start(function($buffer) {
        header('X-Powered-By: Mini Framework');
        header('X-Content-Length: ' . strlen($buffer));
        return $buffer;
    });
});
```

The handlers execute in **reverse** order (Handler 3 → Handler 2 → Handler 1).

---

## Complete Example: Full Request/Response Pipeline

Here's a complete example combining all patterns:

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Phase;
use mini\Lifetime;

// Override services via config files (see _config/Psr/Log/LoggerInterface.php)
// NOT done here - use config files instead

// Request processing - fires when entering Ready phase
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Rate limiting
    // CORS headers
    // Authentication
    // Request logging
});

// Response processing - fires after Ready phase entered
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    $start = microtime(true);

    ob_start(function($buffer) use ($start) {
        // Minify HTML
        if (str_contains(headers_list()[0] ?? '', 'text/html')) {
            $buffer = preg_replace('/\s+/', ' ', $buffer);
        }

        // Add performance headers
        $duration = microtime(true) - $start;
        header("X-Response-Time: " . round($duration * 1000, 2) . "ms");
        header("Content-Length: " . strlen($buffer));

        return $buffer;
    });

    // Compression
    if (extension_loaded('zlib')) {
        ob_start('ob_gzhandler');
    }
});
```

---

## Best Practices

### Service Overrides
- ✅ Create config files in `_config/[namespace]/[ClassName].php`
- ✅ Return properly configured service instances from config files
- ✅ Implement required PSR interfaces when replacing framework services (PSR-3, PSR-16, etc.)
- ❌ Don't try to override by registering services in `app/bootstrap.php` (won't work)

### Phase Hooks for Request Processing
- ✅ Use `onEnteringState(Phase::Ready)` for authentication, logging, headers
- ✅ Call `exit` to short-circuit request processing
- ✅ Set headers and status codes directly with `header()` and `http_response_code()`
- ❌ Don't try to access Scoped services (db(), auth()) - not available until Ready phase entered

### Phase Hooks for Response Processing
- ✅ Use `onEnteredState(Phase::Ready)` with `ob_start()` for response processing
- ✅ Check `headers_list()` to determine content type
- ✅ Use `header()` to add/modify response headers
- ✅ Return the (possibly modified) buffer from handler
- ❌ Don't call `exit` in output handlers (breaks buffer chain)

---

## See Also

- **CLAUDE.md** - Full framework documentation
- **README.md** - Getting started guide
- **REFERENCE.md** - API reference
