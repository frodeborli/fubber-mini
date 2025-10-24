<?php

namespace mini;

use mini\Util\IdentityMap;

/**
 * Tracks model instances loaded from repositories using WeakMap and IdentityMap
 * for automatic save/update detection and object identity consistency
 */
class ModelTracker
{
    /** @var \WeakMap<object, array> Maps loaded models to their original data */
    private static ?\WeakMap $loadedModels = null;

    /** @var \WeakMap<object, string> Maps loaded models to their repository name */
    private static ?\WeakMap $modelRepositories = null;

    /** @var array<string, IdentityMap> Per-repository identity maps */
    private static array $identityMaps = [];

    private static function initializeMaps(): void
    {
        if (self::$loadedModels === null) {
            self::$loadedModels = new \WeakMap();
            self::$modelRepositories = new \WeakMap();
        }
    }

    /**
     * Get or create identity map for a repository
     */
    private static function getIdentityMap(string $repositoryName): IdentityMap
    {
        if (!isset(self::$identityMaps[$repositoryName])) {
            self::$identityMaps[$repositoryName] = new IdentityMap();
        }
        return self::$identityMaps[$repositoryName];
    }

    /**
     * Try to get model from identity map by primary key
     */
    public static function tryGetFromIdentityMap(mixed $primaryKey, string $repositoryName): ?object
    {
        $identityMap = self::getIdentityMap($repositoryName);
        return $identityMap->tryGet($primaryKey);
    }

    /**
     * Mark a model as loaded from a repository and store in identity map
     */
    public static function markAsLoaded(object $model, array $originalData, string $repositoryName, mixed $primaryKey = null): void
    {
        self::initializeMaps();
        self::$loadedModels[$model] = $originalData;
        self::$modelRepositories[$model] = $repositoryName;

        // Store in identity map if primary key is provided
        if ($primaryKey !== null) {
            $identityMap = self::getIdentityMap($repositoryName);
            $identityMap->remember($model, $primaryKey);
        }
    }

    /**
     * Check if a model was loaded from a repository
     */
    public static function isLoaded(object $model): bool
    {
        self::initializeMaps();
        return isset(self::$loadedModels[$model]);
    }

    /**
     * Get the original data for a loaded model
     */
    public static function getOriginalData(object $model): ?array
    {
        self::initializeMaps();
        return self::$loadedModels[$model] ?? null;
    }

    /**
     * Get the repository name for a loaded model
     */
    public static function getRepositoryName(object $model): ?string
    {
        self::initializeMaps();
        return self::$modelRepositories[$model] ?? null;
    }

    /**
     * Check if a loaded model has changes compared to its original data
     */
    public static function hasChanges(object $model): bool
    {
        if (!self::isLoaded($model)) {
            return true; // New model, consider it changed
        }

        $repositoryName = self::getRepositoryName($model);
        if ($repositoryName === null) {
            return true;
        }

        $repo = repositories()->get($repositoryName);
        $currentData = $repo->dehydrate($model);
        $originalData = self::getOriginalData($model);

        return $currentData !== $originalData;
    }

    /**
     * Remove tracking for a model (called after delete)
     */
    public static function untrack(object $model): void
    {
        self::initializeMaps();

        // Remove from identity map if it exists
        $repositoryName = self::$modelRepositories[$model] ?? null;
        if ($repositoryName !== null) {
            $identityMap = self::getIdentityMap($repositoryName);
            $identityMap->forgetObject($model);
        }

        unset(self::$loadedModels[$model]);
        unset(self::$modelRepositories[$model]);
    }
}