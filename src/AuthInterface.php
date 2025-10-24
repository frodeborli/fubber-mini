<?php

namespace mini;

/**
 * Authentication interface for application implementations
 *
 * Applications implement this interface to provide authentication logic.
 * The framework provides an Auth facade with convenience methods that
 * delegate to the registered AuthInterface implementation.
 */
interface AuthInterface
{
    /**
     * Check if a user is currently authenticated
     */
    public function isAuthenticated(): bool;

    /**
     * Get the current authenticated user ID
     *
     * @return mixed User ID or null if not authenticated
     */
    public function getUserId(): mixed;

    /**
     * Get a claim value for the current user
     *
     * Claims are key-value pairs associated with the authenticated user.
     * Common claims include 'role', 'email', 'name', etc.
     *
     * @return mixed Claim value or null if claim doesn't exist or user not authenticated
     */
    public function getClaim(string $name): mixed;

    /**
     * Check if current user has a specific role
     *
     * Roles represent what the user IS (admin, editor, member, etc.)
     */
    public function hasRole(string $role): bool;

    /**
     * Check if current user has a specific permission
     *
     * Permissions represent what the user CAN DO (edit_posts, delete_users, etc.)
     */
    public function hasPermission(string $permission): bool;
}