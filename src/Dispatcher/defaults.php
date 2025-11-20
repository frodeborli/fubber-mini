<?php
/**
 * Mini Framework Default Converters and Exception Handlers
 *
 * This file is loaded after all services are registered (via composer autoload files).
 * It registers default converters for common controller return types and default
 * exception handlers for HTTP errors.
 *
 * Applications can override these by registering more specific converters in their
 * own bootstrap code.
 */

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;
use mini\Dispatcher\HttpDispatcher;
use Psr\Http\Message\ResponseInterface;
use mini\Http\Message\Response;

// ============================================================================
// Register Default Converters (for controller return values)
// ============================================================================

$converters = Mini::$mini->get(ConverterRegistryInterface::class);

// string → ResponseInterface (text/plain responses)
$converters->register(function(string $content): ResponseInterface {
    return new Response($content, ['Content-Type' => 'text/plain; charset=utf-8'], 200);
});

// array → ResponseInterface (JSON responses)
$converters->register(function(array $data): ResponseInterface {
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return new Response($json, ['Content-Type' => 'application/json; charset=utf-8'], 200);
});

// ResponseInterface → ResponseInterface (passthrough)
$converters->register(function(ResponseInterface $response): ResponseInterface {
    return $response;
});

// ============================================================================
// Register Default Exception Handlers
// ============================================================================

$dispatcher = Mini::$mini->get(HttpDispatcher::class);

// Handle NotFoundException → 404
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\NotFoundException $e): ResponseInterface {
    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, 404);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], 404);
});

// Handle AccessDeniedException → 401/403
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\AccessDeniedException $e): ResponseInterface {
    // Determine if user is authenticated to decide between 401 and 403
    $statusCode = 401; // Default to 401 (Unauthorized)

    try {
        $auth = \mini\auth();
        if ($auth->isAuthenticated()) {
            $statusCode = 403; // User is authenticated but lacks permission
        }
    } catch (\Throwable) {
        // Auth not configured, default to 401
    }

    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, $statusCode);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], $statusCode);
});

// Handle BadRequestException → 400
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\BadRequestException $e): ResponseInterface {
    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, 400);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], 400);
});

// Handle generic exceptions (500 Internal Server Error)
$dispatcher->registerExceptionConverter(function(\Throwable $e): ResponseInterface {
    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, 500);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], 500);
});
