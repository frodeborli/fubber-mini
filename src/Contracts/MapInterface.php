<?php

namespace mini\Contracts;

use ArrayAccess;
use Countable;
use IteratorAggregate;

/**
 * Key-value map interface with standard PHP collection compatibility
 *
 * Provides a more expressive API than raw array access while maintaining
 * compatibility with standard PHP collection patterns.
 *
 * @template K of array-key
 * @template V
 * @extends ArrayAccess<K, V>
 * @extends IteratorAggregate<K, V>
 */
interface MapInterface extends ArrayAccess, Countable, IteratorAggregate
{
    /**
     * Check if a key exists in the map
     */
    public function has(mixed $key): bool;

    /**
     * Get a value by key
     * @return V|null
     */
    public function get(mixed $key): mixed;

    /**
     * Set a value for a key
     * @param V|null $value
     */
    public function set(mixed $key, mixed $value): void;

    /**
     * Add a value for a key (throws if key already exists)
     * @param V|null $value
     * @throws \RuntimeException If the key already exists
     */
    public function add(mixed $key, mixed $value): void;

    /**
     * Delete a key from the map
     * @return bool True if the key was deleted, false if it didn't exist
     */
    public function delete(mixed $key): bool;

    /**
     * Get all keys in the map
     * @return array<K>
     */
    public function keys(): array;

    /**
     * Get all values in the map
     * @return array<V>
     */
    public function values(): array;
}
