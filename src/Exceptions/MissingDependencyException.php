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
}
