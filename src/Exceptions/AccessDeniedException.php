<?php

namespace mini\Exceptions;

/**
 * Exception thrown when access to a resource is denied
 *
 * Transport-agnostic - the dispatcher maps this to appropriate response
 * (e.g., 401/403 for HTTP, appropriate error for CLI, etc.)
 */
class AccessDeniedException extends \Exception
{
    public function __construct(string $message = 'Access denied', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
