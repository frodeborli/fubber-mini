<?php

namespace mini\Http\Client;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Base exception for HTTP client errors
 */
class ClientException extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(string|\Stringable $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
    }
}
