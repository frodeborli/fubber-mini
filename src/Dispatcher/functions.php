<?php

/**
 * Dispatcher Feature - Request lifecycle management
 *
 * The HttpDispatcher handles the complete HTTP request lifecycle:
 * - Creates PSR-7 ServerRequest from globals
 * - Delegates to RequestHandlerInterface (Router)
 * - Converts exceptions to HTTP responses
 * - Emits response to browser
 */

namespace mini;

use mini\Dispatcher\HttpDispatcher;

/**
 * Dispatch the current HTTP request
 *
 * Entry point for HTTP applications. Creates the dispatcher and handles the request.
 *
 * Usage:
 * ```php
 * // html/index.php
 * <?php
 * require __DIR__ . '/../vendor/autoload.php';
 * mini\dispatch();
 * ```
 *
 * @return void
 */
function dispatch(): void
{
    Mini::$mini->get(HttpDispatcher::class)->dispatch();
}

/**
 * ============================================================================
 * Dispatcher Service Registration
 * ============================================================================
 */

namespace mini\Dispatcher;

use mini\Mini;
use mini\Lifetime;

// Register the request pipeline (any PSR-15 server) and the SAPI dispatcher (FPM) over it
Mini::$mini->addService(RequestDispatcher::class, Lifetime::Singleton, fn() => new RequestDispatcher());
// The request the current request scope is handling (mini\request())
Mini::$mini->addService(\Psr\Http\Message\ServerRequestInterface::class, Lifetime::Transient, fn() => RequestDispatcher::currentRequest());
Mini::$mini->addService(HttpDispatcher::class, Lifetime::Singleton, fn() => new HttpDispatcher());
