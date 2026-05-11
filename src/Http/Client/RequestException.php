<?php

namespace mini\Http\Client;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Exception thrown for malformed requests
 */
class RequestException extends ClientException implements RequestExceptionInterface
{
    public function __construct(
        private RequestInterface $request,
        string|\Stringable $message,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct((string) $message, $code, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
