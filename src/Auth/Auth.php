<?php

namespace mini\Auth;

use mini\Exceptions\AccessDeniedException;
use mini\Mini;

/**
 * Authentication facade providing convenience methods
 *
 * Delegates to the registered AuthInterface implementation while providing
 * framework-level convenience methods like requireLogin() and requireRole().
 */
final class Auth
{
    /**
     * Get the registered AuthInterface implementation from container
     *
     * @throws \RuntimeException If no auth implementation is registered
     */
    public function getImplementation(): AuthInterface
    {
        try {
            return Mini::$mini->get(AuthInterface::class);
        } catch (\Psr\Container\NotFoundExceptionInterface $e) {
            throw new \RuntimeException('No AuthInterface implementation registered. Create _config/mini/Auth/AuthInterface.php', 0, $e);
        }
    }

    /**
     * Check if a user is currently authenticated
     */
    public function isAuthenticated(): bool
    {
        try {
            return $this->getImplementation()->isAuthenticated();
        } catch (\RuntimeException) {
            return false; // No auth system registered = not authenticated
        }
    }

    /**
     * Get the current authenticated user ID
     *
     * @return mixed User ID or null if not authenticated
     */
    public function getUserId(): mixed
    {
        try {
            return $this->getImplementation()->getUserId();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get a claim value for the current user
     *
     * @return mixed Claim value or null if claim doesn't exist or user not authenticated
     */
    public function getClaim(string $name): mixed
    {
        try {
            return $this->getImplementation()->getClaim($name);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Check if current user has a specific role
     */
    public function hasRole(string $role): bool
    {
        try {
            return $this->getImplementation()->hasRole($role);
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Check if current user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        try {
            return $this->getImplementation()->hasPermission($permission);
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Require user to be authenticated
     *
     * @throws AccessDeniedException If not authenticated
     */
    public function requireLogin(): self
    {
        if (!$this->isAuthenticated()) {
            throw new AccessDeniedException('Authentication required');
        }
        return $this;
    }

    /**
     * Require user to have a specific role
     *
     * @throws AccessDeniedException If user doesn't have the required role
     */
    public function requireRole(string $role): self
    {
        $this->requireLogin(); // First ensure user is logged in

        if (!$this->hasRole($role)) {
            throw new AccessDeniedException("Access denied: requires role '$role'");
        }

        return $this; // Allow fluent chaining
    }

    /**
     * Require user to have a specific permission
     *
     * @throws AccessDeniedException If user doesn't have the required permission
     */
    public function requirePermission(string $permission): self
    {
        $this->requireLogin(); // First ensure user is logged in

        if (!$this->hasPermission($permission)) {
            throw new AccessDeniedException("Access denied: requires permission '$permission'");
        }

        return $this; // Allow fluent chaining
    }
}