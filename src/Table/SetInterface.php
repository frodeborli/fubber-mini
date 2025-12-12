<?php

namespace mini\Table;

use Traversable;

/**
 * Interface for a set of values that supports membership testing
 *
 * Used for IN clause support - allows efficient subqueries where
 * the set can be either in-memory or backed by another table.
 *
 * Sets always yield stdClass objects with named column properties:
 * ```php
 * // Single column set
 * foreach ($set as $member) {
 *     echo $member->status;  // e.g., 'active'
 * }
 *
 * // Multi-column set (composite key)
 * foreach ($set as $member) {
 *     echo $member->org_id . ':' . $member->user_id;
 * }
 * ```
 *
 * Use getColumns() to discover the shape:
 * ```php
 * $cols = $set->getColumns();  // ['status' => ColumnDef, ...]
 * $names = array_keys($set->getColumns());  // ['status']
 * ```
 */
interface SetInterface extends Traversable
{
    /**
     * Get column definitions for this set
     *
     * @return array<string, ColumnDef> Column name => ColumnDef
     */
    public function getColumns(): array;

    /**
     * Check if a value exists in the set
     *
     * The member must have properties matching getColumns() keys:
     * ```php
     * array_keys($set->getColumns());  // ['status']
     * $set->has((object)['status' => 'active']);
     *
     * array_keys($set->getColumns());  // ['org_id', 'user_id']
     * $set->has((object)['org_id' => 5, 'user_id' => 123]);
     * ```
     *
     * @param object $member Object with column properties
     * @return bool True if the member exists in the set
     */
    public function has(object $member): bool;
}
