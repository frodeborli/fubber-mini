<?php

namespace mini;

/**
 * Auth Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Auth feature.
 */

/**
 * Setup authentication system with application implementation
 *
 * Applications should call this during bootstrap to register their
 * AuthInterface implementation factory with the framework.
 *
 * Example:
 *   setupAuth(fn() => new SessionAuth());
 *   setupAuth(fn() => new JWTAuth($_ENV['JWT_SECRET']));
 *
 * The factory is called once per request/fiber to create isolated auth instances.
 *
 * @param \Closure $factory Factory closure that returns AuthInterface
 */
function setupAuth(\Closure $factory): void
{
    Mini::$mini->addService(
        Auth\AuthInterface::class,
        Lifetime::Scoped,
        $factory
    );
}

/**
 * Get the request-scoped auth instance
 *
 * Returns the AuthInterface implementation for this request/fiber.
 * Returns null if no auth system is registered.
 *
 * @return Auth\AuthInterface|null Auth instance or null
 * @throws \LogicException If called outside request context (before bootstrap())
 */
function auth(): ?Auth\AuthInterface
{
    try {
        return Mini::$mini->get(Auth\AuthInterface::class);
    } catch (\Psr\Container\NotFoundExceptionInterface) {
        // Auth not configured - return null
        return null;
    }
    // Let other exceptions (like LogicException for invalid context) propagate
}

/**
 * Check if user is currently authenticated
 */
function is_logged_in(): bool
{
    $auth = auth();
    return $auth !== null && $auth->isAuthenticated();
}

/**
 * Require user to be logged in, redirect to login if not
 *
 * @throws Http\AccessDeniedException If not authenticated
 */
function require_login(): void
{
    $auth = auth();
    if ($auth === null || !$auth->isAuthenticated()) {
        throw new Http\AccessDeniedException('Authentication required');
    }
}

/**
 * Require user to have a specific role
 *
 * @throws Http\AccessDeniedException If user doesn't have the required role
 */
function require_role(string $role): void
{
    require_login(); // First ensure authenticated

    $auth = auth();
    if (!$auth->hasRole($role)) {
        throw new Http\AccessDeniedException("Access denied: requires role '$role'");
    }
}

/**
 * Handle AccessDeniedException with proper 401/403 logic
 */
function handleAccessDeniedException(Http\AccessDeniedException $exception): void
{
    // Determine correct HTTP status based on authentication state
    $auth = auth();
    if ($auth !== null && $auth->isAuthenticated()) {
        // User is authenticated but lacks permission → 403 Forbidden
        showErrorPage(403, $exception);
    } else {
        // User is not authenticated → 401 Unauthorized
        showErrorPage(401, $exception);
    }
}
