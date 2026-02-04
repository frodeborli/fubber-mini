<?php

namespace mini;

use mini\Dispatcher\HttpDispatcher;
use mini\Session\Session;
use mini\Session\SessionInterface;
use mini\Session\SessionMiddleware;

/**
 * Session Feature - Service Registration
 *
 * Registers the Session service and SessionMiddleware.
 * The $_SESSION proxy is installed by HttpDispatcher::installRequestGlobalProxies().
 * Writes are saved immediately to cache, no request-end hook needed.
 * Session cookies are added to responses via PSR-15 middleware.
 */

// Register Session service with Lifetime::Scoped for per-request instances
Mini::$mini->addService(
    SessionInterface::class,
    Lifetime::Scoped,
    fn() => new Session()
);

// Register SessionMiddleware to add session cookies to responses
Mini::$mini->get(HttpDispatcher::class)->addMiddleware(new SessionMiddleware());
