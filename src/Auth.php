<?php

namespace mini;

use mini\Http\AccessDeniedException;

/**
 * Authentication facade providing convenience methods
 *
 * Delegates to the registered AuthInterface implementation while providing
 * framework-level convenience methods like requireLogin() and requireRole().
 */
final class Auth
{
    private static ?AuthInterface $implementation = null;

    /**
     * Register the application's AuthInterface implementation
     */
    public static function setImplementation(AuthInterface $auth): void
    {
        self::$implementation = $auth;
    }

    /**
     * Check if an auth implementation has been registered
     */
    public static function hasImplementation(): bool
    {
        return self::$implementation !== null;
    }

    /**
     * Get the registered AuthInterface implementation
     *
     * @throws \RuntimeException If no auth implementation is registered
     */
    private static function getImplementation(): AuthInterface
    {
        if (self::$implementation === null) {
            throw new \RuntimeException('No AuthInterface implementation registered. Call setupAuth() in your bootstrap.');
        }
        return self::$implementation;
    }

    /**
     * Check if a user is currently authenticated
     */
    public static function isAuthenticated(): bool
    {
        try {
            return self::getImplementation()->isAuthenticated();
        } catch (\RuntimeException) {
            return false; // No auth system registered = not authenticated
        }
    }

    /**
     * Get the current authenticated user ID
     *
     * @return mixed User ID or null if not authenticated
     */
    public static function getUserId(): mixed
    {
        try {
            return self::getImplementation()->getUserId();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get a claim value for the current user
     *
     * @return mixed Claim value or null if claim doesn't exist or user not authenticated
     */
    public static function getClaim(string $name): mixed
    {
        try {
            return self::getImplementation()->getClaim($name);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Check if current user has a specific role
     */
    public static function hasRole(string $role): bool
    {
        try {
            return self::getImplementation()->hasRole($role);
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Check if current user has a specific permission
     */
    public static function hasPermission(string $permission): bool
    {
        try {
            return self::getImplementation()->hasPermission($permission);
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Require user to be authenticated
     *
     * @throws AccessDeniedException If not authenticated
     */
    public static function requireLogin(): void
    {
        if (!self::isAuthenticated()) {
            throw new AccessDeniedException('Authentication required');
        }
    }

    /**
     * Require user to have a specific role
     *
     * @throws AccessDeniedException If user doesn't have the required role
     */
    public static function requireRole(string $role): Auth
    {
        self::requireLogin(); // First ensure user is logged in

        if (!self::hasRole($role)) {
            throw new AccessDeniedException("Access denied: requires role '$role'");
        }

        return new self(); // Allow fluent chaining
    }

    /**
     * Require user to have a specific permission
     *
     * @throws AccessDeniedException If user doesn't have the required permission
     */
    public static function requirePermission(string $permission): Auth
    {
        self::requireLogin(); // First ensure user is logged in

        if (!self::hasPermission($permission)) {
            throw new AccessDeniedException("Access denied: requires permission '$permission'");
        }

        return new self(); // Allow fluent chaining
    }
}