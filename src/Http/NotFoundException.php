<?php

namespace mini\Http;

/**
 * Exception for HTTP 404 Not Found responses
 *
 * Thrown when the requested resource cannot be found.
 * Results in 404.php being rendered if it exists.
 */
class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $previous);
    }
}