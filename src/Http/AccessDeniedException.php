<?php

namespace mini\Http;

/**
 * Exception for HTTP 401/403 Access Denied responses
 *
 * Thrown when the client lacks proper authentication or authorization.
 * Results in 401.php being rendered if it exists, which typically
 * redirects to login with returnTo URL preservation.
 */
class AccessDeniedException extends HttpException
{
    public function __construct(string $message = 'Access Denied', ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, $previous);
    }
}