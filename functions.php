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
 * Render a template with provided variables
 *
 * @param string $template Path to template file (relative to project root)
 * @param array $vars Variables to extract for template
 * @return string Rendered content
 */
function render($template, $vars = []) {
    // Get project root from global state
    $templatePath = Mini::$mini->root . '/' . ltrim($template, '/');

    if (!file_exists($templatePath)) {
        throw new Exception("Template not found: $templatePath");
    }

    // Extract variables for template use
    extract($vars);

    // Start output buffering
    ob_start();

    try {
        // Include the template
        include $templatePath;
    } catch (Throwable $e) {
        ob_end_clean();
        return (string) $e;
    }

    // Get the content and clean buffer
    $content = ob_get_clean();

    return $content;
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
 * Generate URL relative to base_url with optional query parameters
 *
 * @throws Exception If base URL cannot be determined
 */
function url($path = '', array $query = []) {
    $base_url = Mini::$mini->baseUrl;

    if ($base_url === null) {
        throw new Exception('Base URL not configured. Set MINI_BASE_URL environment variable');
    }

    $path = ltrim($path, '/');
    $url = rtrim($base_url, '/') . '/' . $path;

    if (!empty($query)) {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . http_build_query($query);
    }

    return $url;
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

/**
 * Get the database singleton (lazy-initialized)
 *
 * @return Contracts\DatabaseInterface Database instance
 */
function db(): Contracts\DatabaseInterface {
    return Mini::$mini->get(Contracts\DatabaseInterface::class);
}

/**
 * Get cache instance
 *
 * Returns PSR-16 SimpleCache instance from container.
 * With smart fallback: APCu > SQLite in /tmp > Filesystem in /tmp
 *
 * @param string|null $namespace Optional namespace for cache isolation
 * @return \Psr\SimpleCache\CacheInterface Cache instance
 */
function cache(?string $namespace = null): \Psr\SimpleCache\CacheInterface {
    $cache = Mini::$mini->get(\Psr\SimpleCache\CacheInterface::class);

    // Return namespaced cache if namespace provided
    if ($namespace !== null) {
        return new Cache\NamespacedCache($cache, $namespace);
    }

    return $cache;
}


/**
 * Bootstrap the mini framework for controller files
 *
 * Call this at the top of any directly-accessible PHP file in the document root.
 * Sets up error handling, output buffering, and clean URL redirects.
 *
 * Marks entry into request context, enabling access to Scoped services.
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

    // Fire onRequestReceived at the very beginning
    Mini::$mini->onRequestReceived->trigger();

    // Mark that we're now in request handling context
    // This enables access to Scoped services (db(), auth(), etc.)
    Mini::$mini->enterRequestContext();

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

    // Set up exception handler (renders error pages) - only if none exists
    $existingHandler = set_exception_handler(null);
    if ($existingHandler !== null) {
        // Developer has their own exception handler - keep it
        set_exception_handler($existingHandler);
    } else {
        // No handler exists - set Mini's default handler
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

            if ($exception instanceof \mini\Http\AccessDeniedException) {
                handleAccessDeniedException($exception);
            } elseif ($exception instanceof \mini\Http\HttpException) {
                handleHttpException($exception);
            } else {
                try {
                    showErrorPage(500, $exception);
                } catch (\Throwable $e) {
                    http_response_code(500);
                    echo "<h1>Internal Server Error</h1>";
                    echo "<p>An unexpected error occurred.</p>";
                    if (Mini::$mini->debug) {
                        echo "<pre>" . htmlspecialchars($exception->getMessage()) . "</pre>";
                        echo "<hr><p>Error page also failed:</p>";
                        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
                    }
                }
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

    // Fire onAfterBootstrap at the very end
    Mini::$mini->onAfterBootstrap->trigger();
}

/**
 * Router entry point for applications with routing enabled
 *
 * Call this from DOC_ROOT/index.php to enable routing:
 * - Sets up error handling and output buffering
 * - Delegates URL routing to SimpleRouter
 * - Routes loaded from _routes/ directory
 *
 * Route handlers in _routes/ don't need to call bootstrap().
 */
function router(): void
{
    // Set global flag that routing is enabled
    $GLOBALS['mini_routing_enabled'] = true;

    // Bootstrap sets up error handlers, output buffering, etc.
    bootstrap();

    // Delegate routing to SimpleRouter
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $router = new \mini\SimpleRouter();
    $router->handleRequest($requestUri);

    // Explicitly flush output buffer on successful completion
    // (Exception handler discards buffer via ob_end_clean())
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}


/**
 * Handle other HTTP exceptions
 */
function handleHttpException(\mini\Http\HttpException $exception): void
{
    showErrorPage($exception->getStatusCode(), $exception);
}

/**
 * Show error page with fallback logic
 */
function showErrorPage(int $statusCode, \Throwable $exception): void
{
    // Error pages stored in project root (not web-accessible, like 404.php)
    $errorFile = Mini::$mini->root . "/_errors/{$statusCode}.php";

    // If the specific error page doesn't exist, try fallbacks for auth errors
    if (!file_exists($errorFile)) {
        if ($statusCode === 401 && file_exists(Mini::$mini->root . "/_errors/403.php")) {
            $errorFile = Mini::$mini->root . "/_errors/403.php";
        } elseif ($statusCode === 403 && file_exists(Mini::$mini->root . "/_errors/401.php")) {
            $errorFile = Mini::$mini->root . "/_errors/401.php";
        }
    }

    // Set response code
    http_response_code($statusCode);

    if (file_exists($errorFile)) {
        // Make exception available to error page
        $httpException = $exception;
        require $errorFile;
    } else {
        // Fallback error page
        $statusText = getHttpStatusText($statusCode);
        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$statusCode} - {$statusText}</title>
</head>
<body>
    <h1>{$statusCode} - {$statusText}</h1>
    <p>" . htmlspecialchars($exception->getMessage()) . "</p>
</body>
</html>";
    }
}

/**
 * Get HTTP status text for status code
 */
function getHttpStatusText(int $statusCode): string
{
    return match($statusCode) {
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        default => 'Error'
    };
}


