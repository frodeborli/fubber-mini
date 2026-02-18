<?php

namespace mini\Exceptions;

/**
 * Exception thrown when an authenticated user lacks permission
 *
 * Maps to HTTP 403 (Forbidden). The user is authenticated but not
 * authorized to perform this action.
 *
 * For unauthenticated users, throw AuthenticationRequiredException (401) instead.
 */
class AccessDeniedException extends \Exception
{
    public function __construct(string $message = 'Access denied', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
