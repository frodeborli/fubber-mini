<?php

namespace mini\Http;

/**
 * Exception thrown when a response has already been sent
 *
 * Used to signal to the dispatcher that a controller has already sent output
 * using classical PHP (echo, header(), etc.) and no PSR-7 response should be emitted.
 *
 * This is necessary because PSR-15 RequestHandlerInterface::handle() requires
 * a ResponseInterface return value and doesn't allow null.
 *
 * Example:
 * ```php
 * // _routes/legacy-endpoint.php
 * header('Content-Type: text/plain');
 * echo "Hello World";
 * // Router detects void/null return and throws ResponseAlreadySentException
 * ```
 *
 * @see \Psr\Http\Server\RequestHandlerInterface
 */
class ResponseAlreadySentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Response has already been sent using classical PHP output (echo/header)');
    }
}
