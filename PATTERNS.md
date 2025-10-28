# Mini Framework - Common Patterns

This document contains common patterns for extending and customizing Mini framework behavior.

## Table of Contents

- [Overriding Framework Services](#overriding-framework-services)
- [Request Processing (Middleware Pattern)](#request-processing-middleware-pattern)
- [Response Processing (Output Buffering)](#response-processing-output-buffering)

---

## Overriding Framework Services

Mini allows applications to override framework default services by registering custom implementations BEFORE the framework's service registration runs.

### How It Works

The framework registers its default services in:
- `src/Mini.php` - registerCoreServices() (PDO, DatabaseInterface, SimpleCache)
- `src/Logger/functions.php` - LoggerInterface
- `src/I18n/functions.php` - Translator, Fmt

Each service registration checks `if (!Mini::$mini->has(...))` before registering, allowing your application to provide its own implementation.

### Pattern: Register Services in app/bootstrap.php

**1. Create app/bootstrap.php**

```php
<?php
// app/bootstrap.php

use mini\Mini;
use mini\Lifetime;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

// Override Logger with custom implementation (e.g., Sentry, Monolog)
Mini::$mini->addService(LoggerInterface::class, Lifetime::Singleton, function() {
    return new \Monolog\Logger('app', [
        new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Logger::DEBUG),
        new \Monolog\Handler\SentryHandler(/* ... */),
    ]);
});

// Override Cache with Redis
Mini::$mini->addService(CacheInterface::class, Lifetime::Singleton, function() {
    return new \Symfony\Component\Cache\Psr16Cache(
        new \Symfony\Component\Cache\Adapter\RedisAdapter(
            \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection('redis://localhost')
        )
    );
});

// Override PDO with custom database configuration
Mini::$mini->addService(\PDO::class, Lifetime::Scoped, function() {
    $pdo = new PDO('mysql:host=db.example.com;dbname=myapp', 'user', 'pass');
    // Custom PDO configuration
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    return $pdo;
});
```

**2. Register in composer.json**

```json
{
    "autoload": {
        "files": [
            "app/bootstrap.php"
        ]
    }
}
```

**3. Run composer dump-autoload**

```bash
composer dump-autoload
```

### Autoload Order Guarantees

Composer's autoload order ensures:
1. `vendor/fubber/mini/bootstrap.php` - Creates `Mini::$mini` singleton
2. **Your `app/bootstrap.php`** - Registers custom services
3. `vendor/fubber/mini/functions.php` - Framework functions
4. `vendor/fubber/mini/src/Logger/functions.php` - Checks `has()`, skips if already registered
5. Other framework feature files...

Your services are registered FIRST, so framework defaults are skipped.

---

## Request Processing (Middleware Pattern)

Mini doesn't have PSR-15 middleware, but the `onRequestReceived` hook provides the same capabilities using standard PHP patterns.

### Pattern: Use onRequestReceived Hook

The `onRequestReceived` hook fires at the very beginning of `mini\bootstrap()`, before:
- Error handlers are set up
- Output buffering starts
- Request context is entered

This is the perfect place for "before request" middleware logic.

**Example: Authentication Middleware**

```php
<?php
// app/bootstrap.php

use mini\Mini;

// Register authentication check for protected routes
Mini::$mini->onRequestReceived->listen(function() {
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

Mini::$mini->onRequestReceived->listen(function() {
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

Mini::$mini->onRequestReceived->listen(function() {
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

Mini::$mini->onRequestReceived->listen(function() {
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

// Middleware 1: Rate limiting
Mini::$mini->onRequestReceived->listen(function() {
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
Mini::$mini->onRequestReceived->listen(function() {
    $requestId = bin2hex(random_bytes(8));
    $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
    header("X-Request-ID: {$requestId}");
});

// Middleware 3: Security headers
Mini::$mini->onRequestReceived->listen(function() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
});
```

---

## Response Processing (Output Buffering)

The `onAfterBootstrap` hook fires at the end of `mini\bootstrap()`, after:
- Error handlers are configured
- Output buffering has started
- Request context is entered
- All framework features are available

This is the perfect place for "after request" processing using PHP's output buffering.

### Pattern: Custom Output Handler

Register a custom output buffer handler in `onAfterBootstrap`:

**Example: HTML Minification**

```php
<?php
// app/bootstrap.php

Mini::$mini->onAfterBootstrap->listen(function() {
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

Mini::$mini->onAfterBootstrap->listen(function() {
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

Mini::$mini->onAfterBootstrap->listen(function() {
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

Mini::$mini->onAfterBootstrap->listen(function() {
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

Mini::$mini->onAfterBootstrap->listen(function() {
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

Mini::$mini->onAfterBootstrap->listen(function() {
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

Mini::$mini->onAfterBootstrap->listen(function() {
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
use mini\Lifetime;

// Override services
Mini::$mini->addService(\Psr\Log\LoggerInterface::class, Lifetime::Singleton, function() {
    return new \Monolog\Logger('app');
});

// Request processing
Mini::$mini->onRequestReceived->listen(function() {
    // Rate limiting
    // CORS headers
    // Authentication
    // Request logging
});

// Response processing
Mini::$mini->onAfterBootstrap->listen(function() {
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
- ✅ Register in `app/bootstrap.php` autoloaded via composer
- ✅ Use appropriate lifetime (Singleton, Scoped, Transient)
- ✅ Implement required PSR interfaces (PSR-3, PSR-16, etc.)
- ❌ Don't register services after `mini\bootstrap()` is called

### Request Hooks
- ✅ Use `onRequestReceived` for authentication, logging, headers
- ✅ Call `exit` to short-circuit request processing
- ✅ Set headers and status codes directly with `header()` and `http_response_code()`
- ❌ Don't try to access Scoped services (db(), auth()) - not available yet

### Response Hooks
- ✅ Use `onAfterBootstrap` with `ob_start()` for response processing
- ✅ Check `headers_list()` to determine content type
- ✅ Use `header()` to add/modify response headers
- ✅ Return the (possibly modified) buffer from handler
- ❌ Don't call `exit` in output handlers (breaks buffer chain)

---

## See Also

- **CLAUDE.md** - Full framework documentation
- **README.md** - Getting started guide
- **REFERENCE.md** - API reference
