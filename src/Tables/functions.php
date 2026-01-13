<?php

namespace mini;

use mini\Contracts\MapInterface;
use mini\Mini;
use mini\Tables\RepositoryInterface;
use mini\Tables\RepositoryException;
use mini\Util\InstanceStore;

/**
 * Tables Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Tables feature.
 */

/**
 * Get the repositories store
 *
 * Repositories must be registered as factory Closures that return RepositoryInterface instances.
 * This ensures fresh database connections per request in long-running applications.
 *
 * @return MapInterface<string, \Closure> Map of repository factories
 */
function repositories(): MapInterface {
    static $repositories = null;

    if ($repositories === null) {
        $repositories = new InstanceStore(\Closure::class);
    }

    return $repositories;
}

/**
 * Get repository for table/model (convenience function)
 *
 * Repositories are instantiated once per request scope using the factory Closure
 * registered via repositories()->set(). This ensures fresh database connections
 * in long-running applications while maintaining per-request performance.
 *
 * Recommended usage: mini\table(User::class) for proper type hints
 *
 * @template T of object
 * @param class-string<T>|string $name Repository name (preferably class name for type safety)
 * @return Tables\Repository<T> Repository wrapper for the specified table/model
 */
function table(string $name): Tables\Repository {
    static $repositoryCache = null;

    // Initialize WeakMap for per-request caching
    if ($repositoryCache === null) {
        $repositoryCache = new \WeakMap();
    }

    // Get current request scope for caching
    $requestScope = \mini\Mini::$mini->getRequestScope();

    // Initialize cache for this request scope if needed
    if (!isset($repositoryCache[$requestScope])) {
        $repositoryCache[$requestScope] = [];
    }

    // Return cached instance if available for this request
    if (isset($repositoryCache[$requestScope][$name])) {
        return $repositoryCache[$requestScope][$name];
    }

    // Get factory closure from repositories
    $factory = repositories()->get($name);
    if ($factory === null) {
        throw new RepositoryException("Repository '$name' not found. Register it via repositories()->set('$name', fn() => new Repository(...))");
    }

    if (!($factory instanceof \Closure)) {
        throw new RepositoryException("Repository '$name' must be registered as a Closure. Use: repositories()->set('$name', fn() => new Repository(...))");
    }

    // Invoke factory to get fresh repository instance
    $implementation = $factory();

    if (!($implementation instanceof RepositoryInterface)) {
        throw new RepositoryException("Repository factory for '$name' must return an instance of RepositoryInterface");
    }

    // Wrap and cache for this request
    $wrapper = new Tables\Repository($implementation);
    $repositoryCache[$requestScope][$name] = $wrapper;

    return $wrapper;
}

/**
 * Save a model (automatically detects insert vs update)
 *
 * @throws \mini\Tables\ValidationException If validation fails
 * @throws \mini\AccessDeniedException If access is denied
 */
function model_save(object $model): bool
{
    return table(get_class($model))->saveModel($model);
}

/**
 * Delete a model
 *
 * @throws \mini\AccessDeniedException If access is denied
 */
function model_delete(object $model): bool
{
    return table(get_class($model))->deleteModel($model);
}

/**
 * Check if a model has unsaved changes
 */
function model_dirty(object $model): bool
{
    return table(get_class($model))->isDirty($model);
}

/**
 * Validate a model without saving
 *
 * @return array<string, \mini\I18n\Translatable>|null Returns null if valid, errors array if invalid
 */
function model_invalid(object $model): ?array
{
    return table(get_class($model))->validateModel($model);
}
