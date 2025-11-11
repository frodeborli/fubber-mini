<?php

namespace mini\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

/**
 * HTTP Feature - PSR-7 Helper Functions
 *
 * Provides convenience functions for working with PSR-7 HTTP messages.
 * Uses Nyholm's PSR-7 implementation.
 */


/**
 * Create a PSR-7 Response
 *
 * @param int $status HTTP status code
 * @param string $body Response body
 * @return ResponseInterface
 */
function create_response(int $status = 200, string $body = ''): ResponseInterface {
    $factory = new Psr17Factory();
    $response = $factory->createResponse($status);

    if ($body !== '') {
        $response = $response->withBody($factory->createStream($body));
    }

    return $response;
}

/**
 * Create a JSON response
 *
 * @param mixed $data Data to encode as JSON
 * @param int $status HTTP status code
 * @return ResponseInterface
 */
function create_json_response(mixed $data, int $status = 200): ResponseInterface {
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    return create_response($status, $json)->withHeader('Content-Type', 'application/json');
}

/**
 * Send a PSR-7 Response to the client
 *
 * @param ResponseInterface $response
 * @return void
 */
function emit_response(ResponseInterface $response): void {
    // Send status line
    header(sprintf(
        'HTTP/%s %s %s',
        $response->getProtocolVersion(),
        $response->getStatusCode(),
        $response->getReasonPhrase()
    ), true, $response->getStatusCode());

    // Send headers
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header(sprintf('%s: %s', $name, $value), false);
        }
    }

    // Send body
    echo $response->getBody();
}

/**
 * Get current request instance
 *
 * Returns the PSR-7 ServerRequest for the current request scope.
 * Works in both traditional PHP-FPM and async environments (Swoole/RoadRunner).
 *
 * Usage:
 * ```php
 * $query = request()->getQueryParams();
 * $body = request()->getParsedBody();
 * $accept = request()->getHeaderLine('Accept');
 * ```
 *
 * @return ServerRequestInterface Current request
 */
function request(): ServerRequestInterface {
    return \mini\Mini::$mini->get(ServerRequestInterface::class);
}
