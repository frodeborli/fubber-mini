<?php
/**
 * Static Files Feature - Bootstrap
 *
 * Initializes the static file serving feature by:
 * - Registering paths->static PathRegistry
 * - Registering StaticFiles middleware as a service
 */

use mini\Mini;
use mini\Lifetime;
use mini\Util\PathsRegistry;
use mini\Static\StaticFiles;

// Register static paths registry
$primaryStaticPath = $_ENV['MINI_STATIC_ROOT'] ?? (Mini::$mini->root . '/_static');
Mini::$mini->paths->static = new PathsRegistry($primaryStaticPath);

// Add framework's _static path as fallback
$frameworkStaticPath = \dirname((new \ReflectionClass(Mini::class))->getFileName(), 2) . '/_static';
Mini::$mini->paths->static->addPath($frameworkStaticPath);

// Register StaticFiles middleware as singleton service
Mini::$mini->addService(StaticFiles::class, Lifetime::Singleton, fn() => new StaticFiles());
