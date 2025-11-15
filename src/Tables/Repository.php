<?php

namespace mini\Tables;

use mini\Tables\RepositoryInterface;
use mini\Tables\RepositoryException;
use mini\Util\IdentityMap;
use mini\Exceptions\AccessDeniedException;

/**
 * User-friendly repository wrapper with clone-based state tracking
 *
 * Each repository instance manages its own identity map and original state tracking
 * using a clone-based approach for efficient dirty detection and save operations.
 *
 * @template T of object
 * @final
 */
final class Repository
{
    /** @var IdentityMap<T> Identity map for object identity consistency */
    private IdentityMap $identityMap;

    /** @var \WeakMap<T, T> Maps public instances to their original state clones */
    private \WeakMap $originalsMap;

    /**
     * @param RepositoryInterface<T> $implementation
     */
    public function __construct(private RepositoryInterface $implementation)
    {
        $this->identityMap = new IdentityMap();
        $this->originalsMap = new \WeakMap();
    }

    /**
     * Save a model (automatically chooses insert or update)
     *
     * @param T $model
     * @throws ValidationException If validation fails
     * @throws AccessDeniedException If access is denied
     */
    public function saveModel(object $model): bool
    {
        // Check if model needs saving
        if (!$this->isDirty($model)) {
            return true; // No-op if not dirty
        }

        // Access control
        $isTracked = isset($this->originalsMap[$model]);
        if ($isTracked) {
            if (!$this->implementation->canUpdate($model)) {
                throw new AccessDeniedException("Cannot update this record");
            }
        } else {
            if (!$this->implementation->canCreate()) {
                throw new AccessDeniedException("Cannot create new records");
            }
        }

        // Validation
        $errors = $this->implementation->validate($model);
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Smart save logic
        try {
            if ($isTracked) {
                // Update existing record
                $id = $this->getPrimaryKeyValue($model);
                $result = $this->implementation->update($model, $id) > 0;
            } else {
                // Insert new record
                $id = $this->implementation->insert($model);
                $this->setPrimaryKeyValue($model, $id);
                $this->identityMap->remember($model, $id);
                $result = true;
            }

            if ($result) {
                // Update original state tracking
                $this->originalsMap[$model] = clone $model;
            }

            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete a model
     *
     * @param T $model
     * @throws AccessDeniedException If access is denied
     */
    public function deleteModel(object $model): bool
    {
        if (!isset($this->originalsMap[$model])) {
            return false; // Can't delete unsaved model
        }

        if (!$this->implementation->canDelete($model)) {
            throw new AccessDeniedException("Cannot delete this record");
        }

        try {
            $id = $this->getPrimaryKeyValue($model);
            $result = $this->implementation->delete($id) > 0;

            if ($result) {
                // Remove from tracking
                $this->identityMap->forgetById($id);
                unset($this->originalsMap[$model]);
            }

            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a model is invalid and return field-based errors
     *
     * @param T $model
     * @return array|null Returns null if valid, field errors if invalid
     */
    public function isInvalid(object $model): ?array
    {
        return $this->implementation->isInvalid($model);
    }

    /**
     * Check if a model has unsaved changes
     *
     * @param T $model
     */
    public function isDirty(object $model): bool
    {
        $original = $this->originalsMap[$model] ?? null;
        if ($original === null) {
            return true; // New model, not tracked
        }

        // Simple object comparison using serialization for accuracy
        return serialize($model) !== serialize($original);
    }

    /**
     * Load a model by primary key (with identity map)
     *
     * @return T|null
     */
    public function load(mixed $id): ?object
    {
        // First check identity map for existing instance
        $existingModel = $this->identityMap->tryGet($id);
        if ($existingModel !== null) {
            return $existingModel;
        }

        // Load from repository if not in memory
        $model = $this->implementation->load($id);
        if ($model) {
            // Store in identity map and track original state
            $this->identityMap->remember($model, $id);
            $this->originalsMap[$model] = clone $model;
            return $model;
        }
        return null;
    }

    /**
     * Create a new model instance (not saved to storage)
     *
     * @return T
     */
    public function create(): object
    {
        return $this->implementation->create(); // Not marked as loaded
    }

    /**
     * Get all records as a queryable table
     *
     * @return Table<T>
     */
    public function all(): Table
    {
        return $this->implementation->all();
    }

    /**
     * Get the repository name
     */
    public function name(): string
    {
        return $this->implementation->name();
    }

    /**
     * Get primary key value from model
     */
    private function getPrimaryKeyValue(object $model): mixed
    {
        $pkField = $this->implementation->pk();
        $data = $this->implementation->dehydrate($model);
        return $data[$pkField] ?? null;
    }

    /**
     * Set primary key value on model (after insert)
     */
    private function setPrimaryKeyValue(object $model, mixed $id): void
    {
        $pkField = $this->implementation->pk();
        $modelClass = $this->implementation->getModelClass();

        // Use reflection to set the primary key property
        $reflection = new \ReflectionClass($modelClass);
        if ($reflection->hasProperty($pkField)) {
            $property = $reflection->getProperty($pkField);
            $property->setAccessible(true);
            $property->setValue($model, $id);
        }
    }

}