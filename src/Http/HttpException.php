<?php

namespace mini\Http;

use Exception;

/**
 * Base exception for HTTP status code responses
 *
 * Provides structured HTTP error handling with status codes.
 * Used by the router to render appropriate error pages.
 */
abstract class HttpException extends Exception
{
    private int $statusCode;

    public function __construct(int $statusCode, string $message = '', ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Get the HTTP status code for this exception
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the default HTTP status message for this code
     */
    public function getStatusMessage(): string
    {
        return match ($this->statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'Error'
        };
    }
}