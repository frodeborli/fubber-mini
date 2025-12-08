<?php

namespace mini\Database\Virtual;

use mini\Parsing\SQL\AST\SelectStatement;

/**
 * Closure-based virtual table
 *
 * Provides a simple, flexible way to create virtual tables using closures.
 * Each operation (SELECT, INSERT, UPDATE, DELETE) can be implemented independently.
 *
 * SELECT operations MUST yield Row instances (enforced at runtime):
 *
 * ```php
 * new VirtualTable(
 *     selectFn: function(SelectStatement $ast): iterable {
 *         // Optional: yield ordering metadata first
 *         yield new OrderInfo(column: 'id', desc: false);
 *
 *         // Then yield Row instances - REQUIRED!
 *         // Note: Placeholders in $ast->where are already replaced with literal values
 *         foreach ($this->getData() as $rowId => $columns) {
 *             yield new Row($rowId, $columns);
 *         }
 *     }
 * );
 * ```
 *
 * For simple tables that don't optimize ordering:
 *
 * ```php
 * new VirtualTable(
 *     selectFn: function(SelectStatement $ast): iterable {
 *         foreach ($this->getAllRows() as $id => $columns) {
 *             yield new Row($id, $columns);  // Row instances required!
 *         }
 *     }
 * );
 * ```
 *
 * DML operations (INSERT/UPDATE/DELETE) use simplified row ID-based API:
 *
 * ```php
 * new VirtualTable(
 *     insertFn: function(array $row): string|int {
 *         return $this->insertRow($row);  // Return generated ID
 *     },
 *     updateFn: function(array $rowIds, array $changes): int {
 *         return $this->updateRows($rowIds, $changes);  // Return affected count
 *     },
 *     deleteFn: function(array $rowIds): int {
 *         return $this->deleteRows($rowIds);  // Return affected count
 *     }
 * );
 * ```
 */
final class VirtualTable
{
    /**
     * @param callable|null $selectFn function(SelectStatement): iterable<ResultInterface> - Yields OrderInfo then Row instances (params already bound in AST)
     * @param callable|null $insertFn function(array $row): string|int - Returns generated ID
     * @param callable|null $updateFn function(array $rowIds, array $changes): int - Returns affected rows
     * @param callable|null $deleteFn function(array $rowIds): int - Returns affected rows
     */
    public function __construct(
        private $selectFn = null,
        private $insertFn = null,
        private $updateFn = null,
        private $deleteFn = null,
    ) {
    }

    /**
     * Execute SELECT query
     *
     * @param SelectStatement $ast Parsed SELECT query (placeholders already bound to literal values)
     * @return iterable<ResultInterface> Iterable that optionally yields OrderInfo first, then Row instances
     * @throws \RuntimeException if SELECT not supported
     */
    public function select(SelectStatement $ast): iterable
    {
        if ($this->selectFn === null) {
            throw new \RuntimeException("SELECT not supported for this table");
        }
        return ($this->selectFn)($ast);
    }

    /**
     * Insert a single row
     *
     * @param array $row Associative array of column => value
     * @return string|int Generated row ID
     * @throws \RuntimeException if INSERT not supported
     */
    public function insert(array $row): string|int
    {
        if ($this->insertFn === null) {
            throw new \RuntimeException("INSERT not supported for this table");
        }
        return ($this->insertFn)($row);
    }

    /**
     * Update rows by ID
     *
     * @param array $rowIds Array of row IDs to update
     * @param array $changes Associative array of column => value changes
     * @return int Number of rows affected
     * @throws \RuntimeException if UPDATE not supported
     */
    public function update(array $rowIds, array $changes): int
    {
        if ($this->updateFn === null) {
            throw new \RuntimeException("UPDATE not supported for this table");
        }
        return ($this->updateFn)($rowIds, $changes);
    }

    /**
     * Delete rows by ID
     *
     * @param array $rowIds Array of row IDs to delete
     * @return int Number of rows affected
     * @throws \RuntimeException if DELETE not supported
     */
    public function delete(array $rowIds): int
    {
        if ($this->deleteFn === null) {
            throw new \RuntimeException("DELETE not supported for this table");
        }
        return ($this->deleteFn)($rowIds);
    }
}
