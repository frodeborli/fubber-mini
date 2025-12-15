<?php
namespace mini\Table\Index;

use Traversable;

/**
 * Hash index interface for O(1) equality lookups
 *
 * Keys are binary strings (use Index::packInt/packFloat/packString to encode).
 * RowIds are integers (matching SQLite's rowid semantics).
 */
interface HashIndexInterface
{
    /**
     * Insert a key-rowId pair
     */
    public function insert(string $key, int $rowId): void;

    /**
     * Delete a key-rowId pair
     */
    public function delete(string $key, int $rowId): void;

    /**
     * Find all rowIds for a key (O(1) lookup)
     *
     * @return Traversable<int> Yields rowIds
     */
    public function eq(string $key): Traversable;
}
