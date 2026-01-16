<?php

namespace mini\Database;

/**
 * Thrown when a query exceeds the configured timeout
 */
class QueryTimeoutException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message . "\n\nStack trace:\n" . $this->getTraceAsString(), 0, $previous);
    }
}
