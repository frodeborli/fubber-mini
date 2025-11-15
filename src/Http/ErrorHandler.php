<?php

namespace mini\Http;

use Throwable;

/**
 * HTTP Error Handler
 *
 * Handles exceptions and renders appropriate error pages.
 * Only loaded when errors occur, not on every request.
 */
class ErrorHandler
{
    /**
     * Handle exception with appropriate error page
     *
     * @param Throwable $e The exception that was thrown
     * @param int $statusCode HTTP status code to send
     * @param string $statusMessage HTTP status message (e.g., "Not Found")
     * @param string $projectRoot Project root directory
     */
    public static function handleException(Throwable $e, int $statusCode, string $statusMessage, string $projectRoot): void
    {
        // Clear any existing output buffer content
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code($statusCode);

        // Try to find custom error page
        $errorPagePath = $projectRoot . '/' . $statusCode . '.php';

        if (file_exists($errorPagePath)) {
            // Custom error page exists - include it
            // Make exception available to error page
            $exception = $e;
            require $errorPagePath;
        } else {
            // In debug mode, show detailed exception info
            if (\mini\Mini::$mini->debug) {
                self::renderDebugErrorPage($e);
            } else {
                // Production: show generic error page
                self::renderGenericErrorPage($statusCode, $statusMessage, $e->getMessage());
            }
        }
    }

    /**
     * Render a generic error page when no custom error page exists
     */
    private static function renderGenericErrorPage(int $statusCode, string $statusMessage, string $errorMessage): void
    {
        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
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
    <div class=\"error-container\">
        <div class=\"error-code\">$statusCode</div>
        <h1>$statusMessage</h1>
        <p>" . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . "</p>
        <div class=\"back-link\">
            <a href=\"javascript:history.back()\">← Go Back</a> |
            <a href=\"/\">Home</a>
        </div>
    </div>
</body>
</html>";
    }

    /**
     * Render a debug error page with full exception details
     */
    private static function renderDebugErrorPage(Throwable $e): void
    {
        $errorType = get_class($e);
        $shortErrorType = substr($errorType, strrpos($errorType, '\\') + 1);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Unhandled Exception: $shortErrorType (Debug)</title>
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
    <div class=\"debug-container\">
        <h1>🐛 Unhandled Exception: $shortErrorType</h1>

        <div class=\"debug-info\">
            <h3>⚠️ Debug Mode Active</h3>
            <p>This detailed error information is only shown because debug mode is enabled. In production, set <code>config['debug'] = false</code>.</p>
        </div>

        <h2>Exception Details</h2>
        <div class=\"error-type\">$errorType</div>
        <div class=\"error-message\">$message</div>
        <div class=\"error-location\"><strong>File:</strong> $file:$line</div>

        <h2>Stack Trace</h2>
        <div class=\"stack-trace\">$trace</div>

        <div class=\"back-link\">
            <a href=\"javascript:history.back()\">← Go Back</a> |
            <a href=\"/\">Home</a>
        </div>
    </div>
</body>
</html>";
    }
}