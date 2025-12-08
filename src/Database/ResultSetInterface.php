<?php

namespace mini\Database;

/**
 * Interface for database query results
 *
 * Provides a consistent API for consuming query results whether from
 * raw SQL queries (ResultSet) or composed queries (PartialQuery).
 *
 * @template T of array|object
 */
interface ResultSetInterface extends \IteratorAggregate, \JsonSerializable, \Countable
{
    /**
     * Get all rows as an array
     *
     * Warning: Materializes all results into memory.
     *
     * @return array<int, T>
     */
    public function toArray(): array;

    /**
     * Get the first row only
     *
     * @return T|null
     */
    public function one(): mixed;

    /**
     * Get first column values as array
     *
     * @return array<int, mixed>
     */
    public function column(): array;

    /**
     * Get first column of first row
     *
     * @return mixed
     */
    public function field(): mixed;

    /**
     * Hydrate results into entity instances
     *
     * @template E of object
     * @param class-string<E> $class Entity class to instantiate
     * @param array|false $constructorArgs Arguments to pass to constructor, or false to skip constructor
     * @return ResultSetInterface<E>
     */
    public function withEntityClass(string $class, array|false $constructorArgs = false): self;

    /**
     * Hydrate results using a custom closure
     *
     * @template E
     * @param \Closure(array): E $hydrator Function that converts row array to desired type
     * @return ResultSetInterface<E>
     */
    public function withHydrator(\Closure $hydrator): self;
}
