<?php
/**
 * Static Files Feature - Bootstrap
 *
 * Initializes the static file serving feature by:
 * - Registering paths->static PathRegistry
 * - Registering StaticFiles middleware as a service
 * - Adding StaticFiles middleware to HttpDispatcher pipeline
 */

use mini\Mini;
use mini\Lifetime;
use mini\Util\PathsRegistry;
use mini\Static\StaticFiles;
use mini\Dispatcher\HttpDispatcher;

// Register static paths registry
$primaryStaticPath = $_ENV['MINI_STATIC_ROOT'] ?? (Mini::$mini->root . '/_static');
Mini::$mini->paths->static = new PathsRegistry($primaryStaticPath);

// Add framework's _static path as fallback
$frameworkStaticPath = \dirname((new \ReflectionClass(Mini::class))->getFileName(), 2) . '/_static';
Mini::$mini->paths->static->addPath($frameworkStaticPath);

// Register StaticFiles middleware as singleton service
Mini::$mini->addService(StaticFiles::class, Lifetime::Singleton, fn() => new StaticFiles());

// Add StaticFiles middleware to the HttpDispatcher pipeline
// This runs during Bootstrap phase, before Ready phase
$dispatcher = Mini::$mini->get(HttpDispatcher::class);
$dispatcher->addMiddleware(Mini::$mini->get(StaticFiles::class));
