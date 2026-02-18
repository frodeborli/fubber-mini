<?php

namespace mini\Exceptions;

/**
 * Exception thrown when an operation requires authentication
 *
 * Maps to HTTP 401 (Unauthorized). Distinct from AccessDeniedException (403)
 * which means "authenticated but not permitted".
 *
 * Applications can register an exception converter to redirect to a login page:
 * ```php
 * $dispatcher->registerExceptionConverter(function(AuthenticationRequiredException $e): ResponseInterface {
 *     $returnTo = urlencode(request()->getRequestTarget());
 *     return new Response('', ['Location' => "/login/?returnTo=$returnTo"], 303);
 * });
 * ```
 */
class AuthenticationRequiredException extends \Exception
{
    public function __construct(string $message = 'Authentication required', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
