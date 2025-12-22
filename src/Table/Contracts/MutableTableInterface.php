<?php

namespace mini\Table\Contracts;

/**
 * Interface for tables that support INSERT, UPDATE, and DELETE operations
 *
 * UPDATE and DELETE take a query parameter that specifies which rows to affect.
 * The query must be derived from the same underlying table:
 *
 * ```php
 * $table->delete($table->eq('status', 'inactive'));
 * $table->update($table->gt('age', 65), ['retired' => true]);
 * ```
 *
 * Implementations MUST validate that the query is compatible (e.g., same
 * underlying storage) and throw InvalidArgumentException if not.
 */
interface MutableTableInterface extends TableInterface
{
    /**
     * Insert a new row
     *
     * @param array $row Associative array of column => value
     * @return int|string Generated row ID (e.g., auto-increment value)
     */
    public function insert(array $row): int|string;

    /**
     * Update rows matching the query
     *
     * @param TableInterface $query Filtered view specifying which rows to update
     * @param array $changes Associative array of column => value to set
     * @return int Number of rows affected
     * @throws \InvalidArgumentException if query is not from this table
     */
    public function update(TableInterface $query, array $changes): int;

    /**
     * Delete rows matching the query
     *
     * @param TableInterface $query Filtered view specifying which rows to delete
     * @return int Number of rows affected
     * @throws \InvalidArgumentException if query is not from this table
     */
    public function delete(TableInterface $query): int;
}
