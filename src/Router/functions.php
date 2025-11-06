<?php

namespace mini\Router;

use mini\Mini;
use mini\Lifetime;

/**
 * Router module functions and service registration
 *
 * This file is autoloaded by Composer and registers the Router service.
 */

// Register Router service when this file is loaded
if (!Mini::$mini->has(Router::class)) {
    Mini::$mini->addService(Router::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(Router::class));
}
