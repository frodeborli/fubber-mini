<?php

namespace mini\Exceptions;

/**
 * Exception thrown when a requested resource cannot be found
 *
 * Transport-agnostic - the dispatcher maps this to appropriate response
 * (e.g., 404 for HTTP, appropriate error for CLI, etc.)
 */
class ResourceNotFoundException extends \Exception
{
    public function __construct(string $message = 'Resource not found', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
