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
}
