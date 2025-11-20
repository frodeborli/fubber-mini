<?php

namespace mini\Http;

use Throwable;

/**
 * HTTP Error Handler
 *
 * Handles exceptions and renders appropriate error pages using the template system.
 * Templates are resolved in order:
 * 1. Application-specific: _views/errors/{statusCode}.php
 * 2. Framework default: vendor/fubber/mini/_views/errors/{statusCode}.php
 * 3. Debug mode fallback: vendor/fubber/mini/_views/errors/debug.php
 */
class ErrorHandler
{
    /**
     * Render exception as HTML response body
     *
     * @param Throwable $e The exception that was thrown
     * @param int $statusCode HTTP status code (404, 500, etc.)
     * @return string Rendered HTML content
     */
    public static function renderExceptionPage(Throwable $e, int $statusCode): string
    {
        // In debug mode, show detailed exception info
        if (\mini\Mini::$mini->debug) {
            return self::renderDebugPage($e);
        }

        // Try to render status-specific error page (e.g., errors/404.php)
        try {
            return \mini\render("errors/$statusCode.php", [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        } catch (\Exception $renderError) {
            // Template not found or rendering failed, fall back to generic error
            return self::renderFallbackPage($statusCode, $e->getMessage());
        }
    }

    /**
     * Render debug error page with full exception details
     *
     * @param Throwable $e The exception
     * @return string Rendered HTML
     */
    private static function renderDebugPage(Throwable $e): string
    {
        $errorType = get_class($e);
        $shortErrorType = substr($errorType, strrpos($errorType, '\\') + 1);

        try {
            return \mini\render('errors/debug.php', [
                'errorType' => $errorType,
                'shortErrorType' => $shortErrorType,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'exception' => $e,
                'sensitiveValues' => self::collectSensitiveValues(),
            ]);
        } catch (\Exception $renderError) {
            // Even debug template failed, return inline HTML
            return self::renderInlineDebugPage($e, $errorType, $shortErrorType);
        }
    }

    /**
     * Collect sensitive values that should be redacted from error output
     *
     * @return array<string> List of sensitive values to redact
     */
    private static function collectSensitiveValues(): array
    {
        $sensitive = [];
        $sensitivePatterns = ['PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'SALT', 'CREDENTIAL', 'AUTH'];

        // Collect from $_ENV
        foreach ($_ENV as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            foreach ($sensitivePatterns as $pattern) {
                if (stripos($name, $pattern) !== false) {
                    $sensitive[] = $value;
                    break;
                }
            }
        }

        // Also check getenv() for vars not in $_ENV
        foreach ($sensitivePatterns as $pattern) {
            foreach (['DB_PASSWORD', 'DATABASE_PASSWORD', 'MYSQL_PASSWORD', 'APP_KEY', 'APP_SECRET', 'API_KEY', 'API_SECRET', 'JWT_SECRET', 'ENCRYPTION_KEY', 'SALT', 'AUTH_TOKEN'] as $commonName) {
                $value = getenv($commonName);
                if ($value !== false && $value !== '' && !in_array($value, $sensitive, true)) {
                    $sensitive[] = $value;
                }
            }
        }

        // Parse DSN if database is configured
        try {
            $dsn = getenv('DATABASE_URL') ?: getenv('DB_DSN') ?: ($_ENV['DATABASE_URL'] ?? $_ENV['DB_DSN'] ?? null);
            if ($dsn) {
                $parsed = parse_url($dsn);
                if ($parsed) {
                    if (!empty($parsed['pass'])) {
                        $sensitive[] = $parsed['pass'];
                        $sensitive[] = urldecode($parsed['pass']);
                    }
                    if (!empty($parsed['user'])) {
                        $sensitive[] = $parsed['user'];
                    }
                    if (!empty($parsed['host'])) {
                        $sensitive[] = $parsed['host'];
                    }
                    // Database name from path
                    if (!empty($parsed['path'])) {
                        $dbName = ltrim($parsed['path'], '/');
                        if ($dbName) {
                            $sensitive[] = $dbName;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore DSN parsing errors
        }

        // Remove empty values and duplicates
        return array_values(array_unique(array_filter($sensitive, fn($v) => strlen($v) >= 3)));
    }

    /**
     * Fallback error page when template rendering fails
     *
     * @param int $statusCode HTTP status code
     * @param string $message Error message
     * @return string Rendered HTML
     */
    private static function renderFallbackPage(int $statusCode, string $message): string
    {
        $statusMessages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];

        $statusMessage = $statusMessages[$statusCode] ?? 'Error';
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$statusCode - $statusMessage</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background: #f5f5f5; }
        .error-container { max-width: 600px; margin: 2rem auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; margin: 0 0 1rem 0; }
        p { color: #666; line-height: 1.6; }
        .error-code { font-size: 4rem; font-weight: bold; color: #dc3545; margin: 0; }
        .back-link { margin-top: 2rem; }
        .back-link a { color: #007bff; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">$statusCode</div>
        <h1>$statusMessage</h1>
        <p>$safeMessage</p>
        <div class="back-link">
            <a href="javascript:history.back()">← Go Back</a> |
            <a href="/">Home</a>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Inline debug page when template system fails
     *
     * @param Throwable $e The exception
     * @param string $errorType Full exception class name
     * @param string $shortErrorType Short exception class name
     * @return string Rendered HTML
     */
    private static function renderInlineDebugPage(Throwable $e, string $errorType, string $shortErrorType): string
    {
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$errorType (Debug)</title>
    <style>
        body { font-family: 'Consolas', 'Monaco', monospace; margin: 0; padding: 1rem; background: #1e1e1e; color: #d4d4d4; font-size: 14px; line-height: 1.5; }
        .debug-container { max-width: none; background: #2d2d30; padding: 2rem; border-radius: 8px; border: 1px solid #404040; }
        h1 { color: #f44747; margin: 0 0 1rem 0; font-size: 2rem; }
        h2 { color: #569cd6; margin: 2rem 0 1rem 0; font-size: 1.2rem; }
        .error-type { color: #ce9178; font-weight: bold; }
        .error-message { color: #d7ba7d; background: #3c3c3c; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .error-location { color: #9cdcfe; }
        .stack-trace { background: #252526; border: 1px solid #404040; border-radius: 4px; padding: 1rem; overflow-x: auto; white-space: pre; color: #cccccc; }
        .debug-info { background: #0e639c20; border: 1px solid #0e639c; border-radius: 4px; padding: 1rem; margin: 1rem 0; }
        .debug-info h3 { color: #569cd6; margin: 0 0 0.5rem 0; }
        .back-link { margin-top: 2rem; }
        .back-link a { color: #569cd6; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="debug-container">
        <h1>🐛 $errorType</h1>

        <div class="debug-info">
            <h3>⚠️ Debug Mode Active</h3>
            <p>This detailed error information is only shown because debug mode is enabled. In production, set <code>DEBUG=false</code> in your environment.</p>
        </div>

        <h2>Exception Details</h2>
        <div class="error-type">$errorType</div>
        <div class="error-message">$message</div>
        <div class="error-location"><strong>File:</strong> $file:$line</div>

        <h2>Stack Trace</h2>
        <div class="stack-trace">$trace</div>

        <div class="back-link">
            <a href="javascript:history.back()">← Go Back</a> |
            <a href="/">Home</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
}