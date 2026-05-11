<?php
namespace mini\Exceptions;

use Exception;

/**
 * Thrown when an optional dependency is required but not installed
 *
 * Example: Trying to use mailer() without symfony/mailer installed
 */
class MissingDependencyException extends Exception
{
    public function __construct(string|\Stringable $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
    }
}
