<?php

namespace mini\Http\Client;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Base exception for HTTP client errors
 */
class ClientException extends \RuntimeException implements ClientExceptionInterface
{
}
