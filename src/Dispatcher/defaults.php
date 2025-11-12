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

// Handle HttpException (404, 400, 403, etc.)
$dispatcher->registerExceptionConverter(function(\mini\Http\HttpException $e): ResponseInterface {
    $statusCode = $e->getStatusCode();
    $message = $e->getMessage() ?: $e->getStatusMessage();

    // In debug mode, show exception message
    if (!Mini::$mini->debug && $statusCode === 500) {
        $message = 'Internal Server Error';
    }

    $body = sprintf(
        "<!DOCTYPE html><html><head><title>Error %d</title></head>" .
        "<body><h1>Error %d - %s</h1><p>%s</p></body></html>",
        $statusCode,
        $statusCode,
        htmlspecialchars($e->getStatusMessage()),
        htmlspecialchars($message)
    );

    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], $statusCode);
});

// Handle generic exceptions (500 Internal Server Error)
$dispatcher->registerExceptionConverter(function(\Throwable $e): ResponseInterface {
    $statusCode = 500;
    $message = 'Internal Server Error';

    // Show exception message in debug mode
    if (Mini::$mini->debug) {
        $message = $e->getMessage() ?: $message;
    }

    $body = sprintf(
        "<!DOCTYPE html><html><head><title>Error %d</title></head>" .
        "<body><h1>Error %d</h1><p>%s</p></body></html>",
        $statusCode,
        $statusCode,
        htmlspecialchars($message)
    );

    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], $statusCode);
});
