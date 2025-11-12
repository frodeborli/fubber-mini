<?php

namespace mini;

use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP Feature - Functions
 *
 * Provides core HTTP functionality for Mini framework.
 */

/**
 * Get the current ServerRequest
 *
 * Returns the PSR-7 ServerRequest instance for the current request scope.
 * The request is registered by HttpDispatcher during request handling.
 *
 * Usage:
 * ```php
 * $query = request()->getQueryParams();
 * $body = request()->getParsedBody();
 * $accept = request()->getHeaderLine('Accept');
 * ```
 *
 * @return ServerRequestInterface Current request
 */
function request(): ServerRequestInterface {
    return Mini::$mini->get(ServerRequestInterface::class);
}
