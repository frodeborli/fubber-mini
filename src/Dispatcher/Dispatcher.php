<?php

namespace mini\Dispatcher;

use mini\Mini;
use mini\Router\Router;
use mini\Http\HttpException;
use mini\Http\AccessDeniedException;

/**
 * Request dispatcher
 *
 * The Dispatcher is the entry point for all incoming requests (HTTP, CLI, etc.).
 * It's responsible for:
 * - Detecting request type and delegating to appropriate handlers
 * - Setting up request-scoped globals ($_GET, $_POST in event loop contexts)
 * - Catching exceptions during request lifecycle
 * - Rendering error pages
 *
 * Architecture:
 * Bootstrap (app-level) → Dispatcher (request-level) → Router (HTTP) → Routes
 *
 * Future: Can support CLI dispatcher by registering CliRouter similarly.
 */
class Dispatcher
{
    private Router $router;

    public function __construct(Router $router)
    {
        $this->router = $router;
    }

    /**
     * Dispatch the current request
     *
     * Detects request type and delegates to appropriate handler.
     * Currently only supports HTTP requests.
     *
     * @return void
     */
    public function dispatch(): void
    {
        // Future: Detect CLI vs HTTP
        // For now, only HTTP is supported
        $this->dispatchHttp();
    }

    /**
     * Dispatch an HTTP request
     *
     * Handles the complete HTTP request lifecycle:
     * - Determines request URI
     * - Delegates to Router for routing
     * - Catches and handles any exceptions
     * - Flushes output buffer on success
     *
     * @return void
     */
    public function dispatchHttp(): void
    {
        try {
            // Get request URI
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

            // Parse query string to ensure $_GET is populated
            if (str_contains($requestUri, '?')) {
                [$path, $query] = explode('?', $requestUri, 2);
                parse_str($query, $_GET);
                $requestUri = $path;
            }

            // Delegate to router
            $this->router->handleRequest($requestUri);

            // Success - flush output buffer
            if (ob_get_level() > 0) {
                ob_end_flush();
            }

        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Handle an exception during request lifecycle
     *
     * Determines appropriate HTTP status code and renders error page.
     * Handles typed exceptions (HttpException, AccessDeniedException) specially.
     *
     * @param \Throwable $exception The exception to handle
     * @return void
     */
    private function handleException(\Throwable $exception): void
    {
        // Clean output buffer if present
        if (ob_get_level() > 0) {
            ob_clean();
        }

        // Determine status code based on exception type
        if ($exception instanceof AccessDeniedException) {
            $statusCode = $this->getAccessDeniedStatusCode();
        } elseif ($exception instanceof HttpException) {
            $statusCode = $exception->getStatusCode();
        } else {
            $statusCode = 500;
        }

        // Render error page
        $this->renderErrorPage($statusCode, $exception);
    }

    /**
     * Determine correct status code for AccessDeniedException
     *
     * Returns 403 if user is authenticated (lacks permission),
     * 401 if user is not authenticated.
     *
     * @return int HTTP status code (401 or 403)
     */
    private function getAccessDeniedStatusCode(): int
    {
        try {
            $authFacade = \mini\auth();
            return $authFacade->isAuthenticated() ? 403 : 401;
        } catch (\Throwable) {
            // Auth not configured - treat as unauthenticated
            return 401;
        }
    }

    /**
     * Render an error page
     *
     * Looks for custom error templates in _errors/{statusCode}.php.
     * Falls back to 401/403 interchangeably if specific template missing.
     * Renders basic HTML page if no template found.
     *
     * @param int $statusCode HTTP status code
     * @param \Throwable $exception The exception that caused the error
     * @return void
     */
    private function renderErrorPage(int $statusCode, \Throwable $exception): void
    {
        // Set HTTP response code
        http_response_code($statusCode);

        // Look for error template
        $errorFile = Mini::$mini->root . "/_errors/{$statusCode}.php";

        // Fallback logic for auth errors (401/403 interchangeable)
        if (!file_exists($errorFile)) {
            if ($statusCode === 401 && file_exists(Mini::$mini->root . "/_errors/403.php")) {
                $errorFile = Mini::$mini->root . "/_errors/403.php";
            } elseif ($statusCode === 403 && file_exists(Mini::$mini->root . "/_errors/401.php")) {
                $errorFile = Mini::$mini->root . "/_errors/401.php";
            }
        }

        // Render custom error page
        if (file_exists($errorFile)) {
            // Make exception available to template
            $httpException = $exception;
            require $errorFile;
            return;
        }

        // Fallback: Render basic error page
        $statusText = $this->getHttpStatusText($statusCode);
        $message = htmlspecialchars($exception->getMessage());

        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$statusCode} - {$statusText}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 100px auto; padding: 20px; }
        h1 { color: #dc3545; }
    </style>
</head>
<body>
    <h1>{$statusCode} - {$statusText}</h1>
    <p>{$message}</p>
</body>
</html>";
    }

    /**
     * Get human-readable text for HTTP status code
     *
     * @param int $statusCode HTTP status code
     * @return string Status text
     */
    private function getHttpStatusText(int $statusCode): string
    {
        return match($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Error'
        };
    }
}
