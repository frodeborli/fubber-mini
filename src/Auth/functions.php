<?php

namespace mini;

use mini\Auth\Auth;
use mini\Auth\AuthInterface;

/**
 * Auth Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Auth feature.
 */

// Register Auth facade service
Mini::$mini->addService(Auth::class, Lifetime::Singleton, fn() => new Auth());

// Register AuthInterface - apps must provide implementation via config
Mini::$mini->addService(AuthInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(AuthInterface::class));

/**
 * Get the Auth facade instance
 *
 * Returns the Auth facade with convenience methods.
 * The facade delegates to the configured AuthInterface implementation.
 *
 * Usage:
 *   auth()->requireLogin();
 *   auth()->requireRole('admin');
 *   if (auth()->isAuthenticated()) { ... }
 *   $userId = auth()->getUserId();
 *
 * @return Auth Auth facade instance
 */
function auth(): Auth
{
    return Mini::$mini->get(Auth::class);
}

