<?php

namespace mini\Database\Virtual;

/**
 * Runtime interface for values in SQL evaluation
 *
 * This interface abstracts over scalar values and sets (including lazy subqueries).
 * It enables lazy evaluation - subqueries don't materialize until needed,
 * and future implementations could use indexes for accelerated membership checks.
 *
 * SQL treats scalars and single-element sets interchangeably:
 * - `x = 1` is equivalent to `x IN (1)`
 * - A scalar is just a set with one element
 *
 * Implementors:
 * - ScalarValue: wraps a single PHP value (from literals, parameters)
 * - LazySubquery: wraps an unmaterialized subquery, evaluates on demand
 */
interface ValueInterface
{
    /**
     * Check if this value contains a given element
     *
     * For scalars: returns true if $value equals this value
     * For sets: returns true if $value is in the set
     *
     * Uses loose comparison (==) to match SQL semantics.
     *
     * @param mixed $value The value to check for
     * @return bool True if contained
     */
    public function contains(mixed $value): bool;

    /**
     * Compare this value to another
     *
     * For scalars: standard comparison (-1, 0, 1)
     * For sets: compares against first element (SQL scalar subquery semantics)
     *
     * @param mixed $value The value to compare against
     * @return int -1 if less, 0 if equal, 1 if greater
     * @throws \RuntimeException If this is a set with != 1 element (for scalar context)
     */
    public function compareTo(mixed $value): int;

    /**
     * Get the scalar value
     *
     * For scalars: returns the value
     * For sets: returns the single element if exactly one, throws otherwise
     *
     * @return mixed The scalar value
     * @throws \RuntimeException If set has 0 or more than 1 element
     */
    public function getValue(): mixed;

    /**
     * Get all values as array (forces materialization)
     *
     * For scalars: returns single-element array
     * For sets: returns all elements
     *
     * @return array All values
     */
    public function toArray(): array;

    /**
     * Check if this is a scalar (single value) vs a set
     *
     * @return bool True if scalar, false if set/subquery
     */
    public function isScalar(): bool;
}
