<?php

namespace mini\Exceptions;

/**
 * Exception thrown when a request is malformed or invalid
 *
 * Transport-agnostic - the dispatcher maps this to appropriate response
 * (e.g., 400 for HTTP, appropriate error for CLI, etc.)
 */
class BadRequestException extends \Exception
{
    public function __construct(string|\Stringable $message = 'Bad request', ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, 0, $previous);
    }
}
