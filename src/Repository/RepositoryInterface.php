<?php

namespace mini\Repository;

use mini\Table;

/**
 * Repository interface for writable data sources
 *
 * @template T of object
 */
interface RepositoryInterface extends ReadonlyRepositoryInterface
{
    /**
     * Check if model is invalid and return field-based errors
     *
     * @param T $model
     * @return array<string, string>|null Returns null if valid, field errors if invalid
     */
    public function isInvalid(object $model): ?array;

    /** Write operations */
    /** @param T $model */
    public function dehydrate(object $model): array;

    /** @param T $model */
    public function insert(object $model): mixed;

    /** @param T $model */
    public function update(object $model, mixed $id): int;

    public function delete(mixed $id): int;

    /** Metadata */
    public function isReadOnly(): bool;

    /** Write access control methods */
    public function canCreate(): bool;

    /** @param T $model */
    public function canUpdate(object $model): bool;

    /** @param T $model */
    public function canDelete(object $model): bool;
}
