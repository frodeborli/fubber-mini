<?php

namespace mini;

use Exception;
use Throwable;
use Composer\Autoload\ClassLoader;
use mini\Contracts\CollectionInterface;
use mini\Http;
use mini\Mini;
use ReflectionClass;

/**
 * Mini Framework - Global Helper Functions
 *
 * These functions are automatically loaded by Composer and available globally.
 */

/**
 * Get current request instance
 *
 * Alias for \mini\Http\request() in the mini namespace.
 *
 * @return \Psr\Http\Message\ServerRequestInterface
 */
function request(): \Psr\Http\Message\ServerRequestInterface {
    return \mini\Http\request();
}

/**
 * Redirect to URL and exit
 *
 * @param string $url Target URL for redirect
 * @param int $statusCode HTTP status code (301 for permanent, 302 for temporary)
 */
function redirect(string $url, int $statusCode = 302): void {
    http_response_code($statusCode);
    header('Location: ' . $url);
    exit;
}

/**
 * Escape HTML output
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Get current URL
 */
function current_url() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
           . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Generate URL with proper relative path resolution and optional CDN support
 *
 * Resolves paths against baseUrl (or cdnUrl if $cdn = true), handling relative paths like
 * '..', '.', and absolute paths. Strips scheme/host from input URLs to ensure all URLs
 * resolve against the configured base.
 *
 * Examples:
 *   url('/api/users')                    → https://example.com/api/users
 *   url('css/style.css', cdn: true)      → https://cdn.example.com/app/css/style.css
 *   url('../images/logo.png')            → https://example.com/images/logo.png
 *   url('/assets/app.js', ['v' => '2'])  → https://example.com/assets/app.js?v=2
 *
 * Returns UriInterface which is stringable, so works in templates:
 *   <a href="<?= url('/users') ?>">Users</a>
 *
 * Can chain PSR-7 methods for further manipulation:
 *   url('/posts')->withFragment('comments')->withQuery('page=2')
 *
 * @param string|\Psr\Http\Message\UriInterface $path Path to resolve (relative or absolute)
 * @param array $query Query parameters to merge
 * @param bool $cdn Use CDN base URL instead of regular base URL
 * @return \Psr\Http\Message\UriInterface Resolved URL
 * @throws Exception If base URL cannot be determined
 */
function url(string|\Psr\Http\Message\UriInterface $path = '', array $query = [], bool $cdn = false): \Psr\Http\Message\UriInterface {
    $baseUrl = $cdn ? Mini::$mini->cdnUrl : Mini::$mini->baseUrl;

    if ($baseUrl === null) {
        throw new Exception('Base URL not configured. Set MINI_BASE_URL environment variable');
    }

    // Parse base URL
    $base = new \Nyholm\Psr7\Uri($baseUrl);

    // Extract path from input (strip scheme/host if present)
    if ($path instanceof \Psr\Http\Message\UriInterface) {
        $inputPath = $path->getPath();
        $inputQuery = $path->getQuery();
        $inputFragment = $path->getFragment();
    } else {
        // Parse string to extract path, query, fragment
        $parsed = new \Nyholm\Psr7\Uri($path);
        $inputPath = $parsed->getPath();
        $inputQuery = $parsed->getQuery();
        $inputFragment = $parsed->getFragment();
    }

    // Resolve path relative to base path
    $basePath = $base->getPath();

    if ($inputPath === '') {
        // Empty path - use base path
        $resolvedPath = $basePath;
    } elseif ($inputPath[0] === '/') {
        // Absolute path - use as-is
        $resolvedPath = $inputPath;
    } else {
        // Relative path - resolve against base path
        // Append to base path directory
        $baseDir = rtrim(dirname($basePath . '/dummy'), '/');
        $resolvedPath = $baseDir . '/' . $inputPath;
    }

    // Normalize path (handle .. and .)
    $resolvedPath = (string) (new \mini\Util\Path($resolvedPath))->canonical();

    // Merge query parameters: input query + $query array
    parse_str($inputQuery, $inputQueryParams);
    $mergedQuery = http_build_query($inputQueryParams + $query);

    // Build final URI
    return $base
        ->withPath($resolvedPath)
        ->withQuery($mergedQuery)
        ->withFragment($inputFragment);
}

/**
 * Flash message functions
 */
function flash_set($type, $message) {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get() {
    if (!isset($_SESSION['flash'])) {
        return [];
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}


/**
 * Start session if not already started
 *
 * Safe wrapper around session_start() that prevents notices
 * when session is already active or disabled.
 *
 * @return bool True if session was started or already active, false if disabled or failed to start
 */
function session(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        return session_start();
    }
    return session_status() === PHP_SESSION_ACTIVE;
}

// Note: db() and cache() helpers are now in src/Database/functions.php and src/Cache/functions.php


/**
 * Bootstrap the mini framework for controller files
 *
 * Call this at the top of any directly-accessible PHP file in the document root.
 * Sets up error handling, output buffering, and clean URL redirects.
 *
 * Transitions application from Bootstrap to Ready phase, enabling access to Scoped services.
 *
 * Safe to call multiple times (idempotent after first call).
 */
function bootstrap(): void
{
    static $initialized = false;
    if ($initialized) {
        return; // Already bootstrapped
    }
    $initialized = true;

    // Transition to Ready phase - enables request handling and access to Scoped services
    Mini::$mini->phase->trigger(Phase::Ready);

    // Clean up pre-existing output handlers
    $previousLevel = -1;
    while (ob_get_level() > 0 && ob_get_level() !== $previousLevel) {
        $previousLevel = ob_get_level();
        @ob_end_clean();
    }

    // Set up error handler (converts errors to exceptions)
    set_error_handler(function($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    // Set up exception handler (fallback for bootstrap errors) - only if none exists
    $existingHandler = set_exception_handler(null);
    if ($existingHandler !== null) {
        // Developer has their own exception handler - keep it
        set_exception_handler($existingHandler);
    } else {
        // No handler exists - set Mini's fallback handler
        // Note: When using dispatch(), Dispatcher handles exceptions during request lifecycle
        set_exception_handler(function(\Throwable $exception) {
            error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . " line " . $exception->getLine());
            error_log("Stack trace: " . $exception->getTraceAsString());

            if (headers_sent()) {
                if (Mini::$mini->debug) {
                    echo $exception;
                } else {
                    echo get_class($exception) . " thrown in " . $exception->getFile() . " line " . $exception->getLine();
                }
                die();
            }

            if (ob_get_level() > 0) {
                ob_clean();
            }

            // Render basic error page
            http_response_code(500);
            echo "<h1>500 - Internal Server Error</h1>";
            if (Mini::$mini->debug) {
                echo "<pre>" . htmlspecialchars($exception->getMessage()) . "\n\n";
                echo $exception->getTraceAsString() . "</pre>";
            } else {
                echo "<p>An unexpected error occurred.</p>";
            }
        });
    }

    // Start unlimited output buffering for exception recovery
    // Buffer size 0 = unlimited, never auto-flush (prevents partial output on errors)
    ob_start(null, 0);

    // Parse application/json request bodies to $_POST (PHP doesn't do this natively)
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $_POST = $data;
        }
    }

    // If routing is enabled and accessing /index.php directly, redirect to /
    if (isset($GLOBALS['mini_routing_enabled']) && $GLOBALS['mini_routing_enabled']) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';

        // Redirect /index.php to /
        if ($path === '/index.php' || str_ends_with($path, '/index.php')) {
            $redirectTo = rtrim(dirname($path), '/') ?: '/';
            if ($query) {
                $redirectTo .= '?' . $query;
            }

            http_response_code(301);
            header('Location: ' . $redirectTo);
            exit;
        }
    }
}

/**
 * Router entry point for applications with routing enabled
 *
 * Call this from DOC_ROOT/index.php to enable routing:
 * - Sets up error handling and output buffering
 * - Delegates URL routing to Router
 * - Routes loaded from _routes/ directory
 *
 * Route handlers in _routes/ don't need to call bootstrap().
 */
// dispatch() function moved to src/Dispatcher/functions.php

/**
 * @deprecated Use dispatch() instead. Will be removed in future version.
 */
function router(): void
{
    // Set global flag that routing is enabled
    $GLOBALS['mini_routing_enabled'] = true;

    // Bootstrap sets up error handlers, output buffering, etc.
    bootstrap();

    // Delegate routing to Router
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $router = Mini::$mini->get(\mini\Router\Router::class);
    $router->handleRequest($requestUri);

    // Explicitly flush output buffer on successful completion
    // (Exception handler discards buffer via ob_end_clean())
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}


/**
 * Create a CSRF token for a specific action
 *
 * Convenience wrapper around new CSRF().
 *
 * @param string $action Action name (e.g., 'delete-post', 'update-settings')
 * @param string $fieldName HTML field name (default: '__nonce__')
 * @return CSRF CSRF token object
 */
function csrf(string $action, string $fieldName = '__nonce__'): CSRF {
    return new CSRF($action, $fieldName);
}
