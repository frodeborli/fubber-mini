<?php

namespace mini\Http;

/**
 * Exception for HTTP 400 Bad Request responses
 *
 * Thrown when the client's request is malformed or invalid.
 * Results in 400.php being rendered if it exists.
 */
class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, $previous);
    }
}