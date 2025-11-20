<?php

namespace mini\Exceptions;

/**
 * Exception thrown when a required configuration file is missing
 *
 * Indicates that a feature requires configuration in _config/ before use.
 */
class ConfigurationRequiredException extends \RuntimeException
{
    public function __construct(string $configFile, string $purpose, ?\Throwable $previous = null)
    {
        $message = "Configuration required: Please create _config/{$configFile} to configure {$purpose}.";
        parent::__construct($message, 0, $previous);
    }
}
