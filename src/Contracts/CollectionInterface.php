<?php

namespace mini\Contracts;

use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Traversable collection with functional transformation methods
 *
 * Represents a sequence of items that can be iterated, counted, and transformed
 * using functional operations like map() and filter(). All transformation methods
 * return new CollectionInterface instances (immutable).
 *
 * @template T
 * @extends IteratorAggregate<int, T>
 */
interface CollectionInterface extends IteratorAggregate, Countable, JsonSerializable
{
    /**
     * Transform each item using a closure
     *
     * Returns a new collection with each item transformed by the closure.
     *
     * @template U
     * @param Closure(T): U $fn
     * @return CollectionInterface<U>
     */
    public function map(Closure $fn): CollectionInterface;

    /**
     * Filter items using a closure
     *
     * Returns a new collection containing only items for which the closure
     * returns true. Keys are re-indexed (0, 1, 2, ...).
     *
     * @param Closure(T): bool $fn
     * @return CollectionInterface<T>
     */
    public function filter(Closure $fn): CollectionInterface;

    /**
     * Get the first item, or null if empty
     *
     * @return T|null
     */
    public function first(): mixed;

    /**
     * Get the last item, or null if empty
     *
     * @return T|null
     */
    public function last(): mixed;

    /**
     * Check if collection is empty
     */
    public function isEmpty(): bool;

    /**
     * Reduce collection to a single value
     *
     * @template U
     * @param Closure(U, T): U $fn
     * @param U $initial
     * @return U
     */
    public function reduce(Closure $fn, mixed $initial): mixed;

    /**
     * Get all items as an array
     *
     * @return array<int, T>
     */
    public function toArray(): array;

    /**
     * Check if any item matches the predicate
     *
     * @param Closure(T): bool $fn
     */
    public function any(Closure $fn): bool;

    /**
     * Check if no items match the predicate
     *
     * @param Closure(T): bool $fn
     */
    public function none(Closure $fn): bool;

    /**
     * Check if all items match the predicate
     *
     * @param Closure(T): bool $fn
     */
    public function all(Closure $fn): bool;

    /**
     * Find the first item matching the predicate
     *
     * @param Closure(T): bool $fn
     * @return T|null
     */
    public function find(Closure $fn): mixed;
}
