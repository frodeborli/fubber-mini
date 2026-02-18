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

// string|int|float|bool → ResponseInterface (JSON scalar responses)
$converters->register(function(string|int|float|bool $value): ResponseInterface {
    $json = json_encode($value, JSON_THROW_ON_ERROR);
    return new Response($json, ['Content-Type' => 'application/json; charset=utf-8'], 200);
});

// array|stdClass → ResponseInterface (JSON responses)
$converters->register(function(array|\stdClass $data): ResponseInterface {
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return new Response($json, ['Content-Type' => 'application/json; charset=utf-8'], 200);
});

// JsonSerializable → ResponseInterface (JSON responses)
$converters->register(function(\JsonSerializable $data): ResponseInterface {
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

// Handle AuthenticationRequiredException → 401
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\AuthenticationRequiredException $e): ResponseInterface {
    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, 401);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], 401);
});

// Handle AccessDeniedException → 403
$dispatcher->registerExceptionConverter(function(\mini\Exceptions\AccessDeniedException $e): ResponseInterface {
    $body = \mini\Http\ErrorHandler::renderExceptionPage($e, 403);
    return new Response($body, ['Content-Type' => 'text/html; charset=utf-8'], 403);
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
