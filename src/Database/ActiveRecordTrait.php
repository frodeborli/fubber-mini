<?php
namespace mini\Database;

use function mini\db;

/**
 * Shared Active Record functionality
 *
 * Provides query building and entity management without committing to
 * instance methods (ModelTrait) or static methods (RepositoryTrait).
 *
 * This trait is used internally by ModelTrait and RepositoryTrait.
 * You typically don't use this directly - use ModelTrait or RepositoryTrait instead.
 *
 * @template T of object
 */
trait ActiveRecordTrait {
    abstract protected static function getTableName(): string;
    abstract protected static function getPrimaryKey(): string;
    abstract protected static function getEntityClass(): string;

    /**
     * Get the database connection for this entity
     *
     * Override to use vdb() or other DatabaseInterface implementations.
     *
     * @return DatabaseInterface
     */
    protected static function getDatabase(): DatabaseInterface {
        return db();
    }

    /**
     * Convert entity to array for database operations
     *
     * @param object $entity The entity to dehydrate
     * @return array Associative array of column => value
     */
    abstract protected static function dehydrate(object $entity): array;

    /**
     * @return PartialQuery<T>
     */
    public static function query(): PartialQuery {
        return static::getDatabase()->partialQuery(static::getTableName())
            ->withEntityClass(static::getEntityClass(), false);
    }

    /**
     * @return T|null
     */
    public static function find(mixed $id): ?object {
        $pk = static::getPrimaryKey();
        return static::query()
            ->eq($pk, $id)
            ->limit(1)
            ->one();
    }

    /**
     * Internal persist implementation
     *
     * @param object $entity Entity to save
     * @return int Number of affected rows
     */
    protected static function persistEntity(object $entity): int {
        $pk = static::getPrimaryKey();
        $data = static::dehydrate($entity);

        // If PK is set and not null, this is an UPDATE
        if (isset($entity->{$pk}) && $entity->{$pk} !== null) {
            $id = $entity->{$pk};
            unset($data[$pk]); // Don't update the PK itself

            $affected = static::getDatabase()->update(
                static::query()->eq($pk, $id)->limit(1),
                $data
            );
        } else {
            // This is an INSERT
            unset($data[$pk]); // Don't try to insert null/empty PK

            $entity->{$pk} = static::getDatabase()->insert(
                static::getTableName(),
                $data
            );

            $affected = 1; // insert() succeeded (would throw otherwise)
        }

        return $affected;
    }

    /**
     * Internal remove implementation
     *
     * @param object $entity Entity to delete
     * @return int Number of affected rows
     */
    protected static function removeEntity(object $entity): int {
        $pk = static::getPrimaryKey();
        if ($entity->{$pk} === null) {
            throw new \RuntimeException("Cannot delete entity without primary key set");
        }

        return static::getDatabase()->delete(
            static::query()->eq($pk, $entity->{$pk})->limit(1)
        );
    }
}
