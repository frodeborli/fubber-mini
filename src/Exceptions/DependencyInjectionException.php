<?php
namespace mini\Exceptions;

use Psr\Container\ContainerExceptionInterface;

/**
 * Thrown when dependency injection fails
 *
 * This exception indicates that a required dependency could not be resolved
 * during constructor or method injection. Common causes:
 * - No named argument provided and no service registered for the type
 * - Parameter has no type hint and no named argument provided
 * - Variadic parameter provided with non-array value
 */
class DependencyInjectionException extends \Exception implements ContainerExceptionInterface
{
}
