<?php

namespace mini\Database;

/**
 * Trait providing table() method for DatabaseInterface implementations
 *
 * This trait allows database implementations to easily support
 * the partial query builder functionality by simply using this trait.
 *
 * Example:
 * ```php
 * class PDODatabase implements DatabaseInterface
 * {
 *     use PartialQueryableTrait;
 *     // ... rest of implementation
 * }
 * ```
 */
trait PartialQueryableTrait
{
    /**
     * Create a partial query builder for the specified table
     *
     * @param string $table Table name
     * @return PartialQuery Immutable query builder
     */
    public function table(string $table): PartialQuery
    {
        return new PartialQuery($this, $table);
    }
}
