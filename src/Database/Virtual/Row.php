<?php

namespace mini\Database\Virtual;

/**
 * Row object for Virtual Tables
 *
 * Virtual tables MUST yield Row instances instead of plain arrays.
 * This enforces the contract that every row has a unique ID.
 *
 * Usage:
 * ```php
 * selectFn: function($ast, $collator): iterable {
 *     foreach ($data as $id => $columns) {
 *         yield new Row($id, $columns);
 *     }
 * }
 * ```
 */
final class Row implements ResultInterface
{
    /**
     * @param string|int $id Unique row identifier (required for UPDATE/DELETE)
     * @param array $columns Associative array of column => value
     */
    public function __construct(
        public readonly string|int $id,
        public readonly array $columns
    ) {
    }

    /**
     * Get a column value
     */
    public function get(string $column): mixed
    {
        return $this->columns[$column] ?? null;
    }

    /**
     * Check if column exists
     */
    public function has(string $column): bool
    {
        return array_key_exists($column, $this->columns);
    }

    /**
     * Get all columns as array (for compatibility)
     */
    public function toArray(): array
    {
        return $this->columns;
    }
}
