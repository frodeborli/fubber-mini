<?php

namespace mini\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Exception thrown when a requested service is not found in the container
 *
 * Indicates that no service has been registered for the requested identifier.
 */
class NotFoundException extends \Exception implements NotFoundExceptionInterface
{
    public function __construct(string|\Stringable $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
    }
}
