<?php

namespace mini\Table;

/**
 * Interface for tables that support INSERT, UPDATE, and DELETE operations
 *
 * UPDATE and DELETE operate on the current filtered state:
 *
 * ```php
 * $table->eq('status', 'inactive')->delete();           // DELETE WHERE status = 'inactive'
 * $table->gt('age', 65)->update(['retired' => true]);   // UPDATE SET retired = true WHERE age > 65
 * ```
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
     * Update all rows matching current filters
     *
     * @param array $changes Associative array of column => value to set
     * @return int Number of rows affected
     */
    public function update(array $changes): int;

    /**
     * Delete all rows matching current filters
     *
     * @return int Number of rows affected
     */
    public function delete(): int;
}
