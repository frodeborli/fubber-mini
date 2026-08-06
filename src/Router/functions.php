<?php

namespace mini\Router;

use mini\Mini;
use mini\Lifetime;

/**
 * Router module functions and service registration
 *
 * This file is autoloaded by Composer and registers the Router service.
 */

/**
 * Router Feature - URL routing and request handling
 *
 * File-based routing that maps URLs to PHP files in _routes/ directory.
 * Implements PSR-15 RequestHandlerInterface for standard HTTP request handling.
 *
 * The Router resolves request paths to route files and handles their return values:
 * - ResponseInterface → return as-is
 * - RequestHandlerInterface → invoked with the prefix-scoped request
 * - Closure → wrapped in ConverterHandler (inline handler)
 * - Anything else (including direct echo/header output) → RuntimeException
 */

// Register routes path registry
$primaryRoutesPath = $_ENV['MINI_ROUTES_ROOT'] ?? (Mini::$mini->root . '/_routes');
Mini::$mini->paths->routes = new \mini\Util\PathsRegistry($primaryRoutesPath);

// Register Router service
Mini::$mini->addService(Router::class, Lifetime::Singleton, fn() => new Router());
