<?php

namespace mini\Exceptions;

use Psr\Container\ContainerExceptionInterface;

/**
 * Exception thrown for service container errors
 *
 * Indicates a problem with service registration, resolution, or lifecycle.
 */
class ContainerException extends \Exception implements ContainerExceptionInterface
{
    public function __construct(string|\Stringable $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
    }
}
