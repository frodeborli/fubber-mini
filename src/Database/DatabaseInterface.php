<?php

namespace mini\Database;

/**
 * Database interface for the Mini framework
 *
 * Provides a clean abstraction over database operations while maintaining
 * the simple, ergonomic API that makes database work pleasant.
 */
interface DatabaseInterface
{
    /**
     * Execute query and return all results as associative arrays
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return array Array of associative arrays (rows)
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute query and return first row only as associative array
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return array|null Associative array for the row, or null if no results
     */
    public function queryOne(string $sql, array $params = []): ?array;

    /**
     * Execute query and return first column of first row
     *
     * Useful for COUNT(), MAX(), single field lookups, etc.
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return mixed The field value, or null if no results
     */
    public function queryField(string $sql, array $params = []): mixed;

    /**
     * Execute query and return first column values as array
     *
     * Useful for getting arrays of IDs, names, etc.
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return array Array of scalar values from first column
     */
    public function queryColumn(string $sql, array $params = []): array;

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     *
     * @param string $sql SQL statement with parameter placeholders
     * @param array $params Parameters to bind to the statement
     * @return bool True on success, false on failure
     */
    public function exec(string $sql, array $params = []): bool;

    /**
     * Get the last inserted row ID
     *
     * @return string|null The row ID of the last inserted row, or null
     */
    public function lastInsertId(): ?string;

    /**
     * Check if a table exists in the database
     *
     * Note: Implementation may be database-specific
     *
     * @param string $tableName Name of the table to check
     * @return bool True if table exists, false otherwise
     */
    public function tableExists(string $tableName): bool;

    /**
     * Execute a closure within a database transaction
     *
     * Starts a transaction, executes the closure, and commits if successful.
     * If the closure throws an exception, the transaction is rolled back.
     * Supports nested transactions by maintaining a transaction depth counter.
     *
     * @param \Closure $task The task to execute within the transaction
     * @return mixed The return value of the closure
     * @throws \Exception If the transaction fails or the closure throws
     */
    public function transaction(\Closure $task): mixed;

    /**
     * Insert a row into a table
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value pairs
     * @return string|null The last insert ID, or null on failure
     */
    public function insert(string $table, array $data): ?string;

    /**
     * Update rows in a table
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value pairs to update
     * @param string $where WHERE clause (without the WHERE keyword)
     * @param array $whereParams Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, string $where = '', array $whereParams = []): int;

    /**
     * Delete rows from a table
     *
     * @param string $table Table name
     * @param string $where WHERE clause (without the WHERE keyword)
     * @param array $whereParams Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function delete(string $table, string $where = '', array $whereParams = []): int;
}
