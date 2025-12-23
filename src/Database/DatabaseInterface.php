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
     * Execute a SELECT query and return a composable PartialQuery
     *
     * Returns a PartialQuery that can be iterated, further composed, or used
     * for updates/deletes (if single-table). Rows are returned as stdClass objects.
     *
     * Example:
     * ```php
     * // Iterate directly
     * foreach (db()->query('SELECT * FROM users WHERE active = ?', [1]) as $row) {
     *     echo $row->name;
     * }
     *
     * // Compose further
     * $admins = db()->query('SELECT * FROM users')
     *     ->eq('role', 'admin')
     *     ->order('name')
     *     ->limit(10);
     *
     * // Use for delete (single-table queries only)
     * db()->delete(db()->query('SELECT * FROM users')->eq('status', 'inactive'));
     * ```
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return PartialQuery Composable query object
     */
    public function query(string $sql, array $params = []): PartialQuery;

    /**
     * Execute query and return first row only as object
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return object|null Row as object, or null if no results
     */
    public function queryOne(string $sql, array $params = []): ?object;

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
     * @return int Number of affected rows
     */
    public function exec(string $sql, array $params = []): int;

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
     *
     * The closure receives the DatabaseInterface as its first parameter.
     *
     * Example:
     * ```php
     * db()->transaction(function(DatabaseInterface $db) {
     *     $db->exec("INSERT INTO users (name) VALUES (?)", ['John']);
     *     $db->exec("INSERT INTO logs (action) VALUES (?)", ['user_created']);
     * });
     * ```
     *
     * @param \Closure $task The task to execute within the transaction
     * @return mixed The return value of the closure
     * @throws \RuntimeException If the backend doesn't support transactions
     * @throws \RuntimeException If called while already in a transaction (nested transactions not supported)
     * @throws \Throwable Re-throws any exception from the closure after rollback
     */
    public function transaction(\Closure $task): mixed;

    /**
     * Get the SQL dialect for this database connection
     *
     * Used by PartialQuery to generate dialect-specific SQL.
     * Implementations should detect the dialect from the connection.
     *
     * @return SqlDialect SQL dialect enum
     */
    public function getDialect(): SqlDialect;

    /**
     * Quotes a value for safe use in SQL query strings
     *
     * WARNING: Quoting parameters this way is prone to vulnerabilities
     * if done incorrectly - especially if the database connection has
     * a different character set than expected. Prefer using parameterized
     * queries via the query() and exec() methods whenever possible. This
     * function is provided for edge cases, debugging and dynamic SQL generation.
     *
     * @param mixed $value Value to quote
     * @return string Quoted value safe for SQL
     */
    public function quote(mixed $value): string;

    /**
     * Quotes an identifier (table name, column name) for safe use in SQL
     *
     * Handles reserved words and special characters in identifiers.
     *
     * @param string $identifier Table or column name
     * @return string Quoted identifier safe for SQL
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Delete rows matching a partial query
     *
     * Respects WHERE clauses and LIMIT from the query.
     * Ignores SELECT, ORDER BY, and OFFSET.
     *
     * Example:
     * ```php
     * $deleted = db()->delete(User::inactive());
     * ```
     *
     * @param PartialQuery $query Query defining which rows to delete
     * @return int Number of affected rows
     */
    public function delete(PartialQuery $query): int;

    /**
     * Update rows matching a partial query
     *
     * Respects WHERE clauses and LIMIT from the query.
     * Ignores SELECT, ORDER BY, and OFFSET.
     *
     * Use string for raw SQL expressions:
     * ```php
     * db()->update($query, 'login_count = login_count + 1')
     * db()->update($query, 'last_seen = ?, status = ?', [$now, 'active'])
     * ```
     *
     * Use array for simple assignments (values passed as-is):
     * ```php
     * db()->update($query, ['status' => 'archived', 'updated_at' => date('Y-m-d H:i:s')])
     * ```
     *
     * WARNING: Values are NOT converted automatically. You must handle
     * conversion yourself (dates to strings, objects to JSON, etc).
     *
     * @param PartialQuery $query Query defining which rows to update
     * @param string|array $set Either raw SQL expression or ['column' => 'value'] array
     * @param array $params Parameters for placeholders in SQL expression (only used when $set is string)
     * @return int Number of affected rows
     */
    public function update(PartialQuery $query, string|array $set, array $params = []): int;

    /**
     * Insert a new row into a table
     *
     * Performs a simple INSERT operation. Use this when you know the row doesn't exist.
     * For INSERT or UPDATE behavior, use upsert() instead.
     *
     * Example:
     * ```php
     * db()->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
     * $userId = db()->lastInsertId();
     * ```
     *
     * WARNING: Values are NOT converted automatically. You must handle
     * conversion yourself (dates to strings, JSON encoding, etc).
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value pairs
     * @return string The last insert ID (for auto-increment columns)
     * @throws \InvalidArgumentException If data is empty
     */
    public function insert(string $table, array $data): string;

    /**
     * Insert a row, or update if conflict on unique columns
     *
     * Performs an "UPSERT" operation. If a row with the specified unique
     * column values already exists, updates that row instead of inserting.
     *
     * Example:
     * ```php
     * db()->upsert('users', ['email' => 'john@example.com', 'name' => 'John'], 'email');
     * db()->upsert('user_prefs', ['user_id' => 1, 'key' => 'theme', 'value' => 'dark'], 'user_id', 'key');
     * ```
     *
     * Note: MySQL's ON DUPLICATE KEY UPDATE ignores $conflictColumns and triggers
     * on any unique constraint. Behavior may differ across databases.
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value pairs
     * @param string ...$conflictColumns Column(s) that define uniqueness
     * @return int Number of affected rows
     */
    public function upsert(string $table, array $data, string ...$conflictColumns): int;

    /**
     * Build a temporary table containing schema metadata
     *
     * Creates (or refreshes) a temporary table with database schema information,
     * including columns and indexes. The table can then be queried using standard SQL.
     *
     * Table structure:
     * - table_name TEXT: Name of the table
     * - name TEXT: Column name or index name
     * - type TEXT: 'column', 'primary', 'unique', or 'index'
     * - data_type TEXT: Data type for columns (NULL for indexes)
     * - is_nullable INTEGER: 1 if nullable, 0 if NOT NULL (NULL for indexes)
     * - default_value TEXT: Default value expression (NULL for indexes)
     * - ordinal INTEGER: Position (1-based for columns, NULL for indexes)
     * - extra TEXT: For indexes: comma-separated list of indexed columns
     *
     * Example:
     * ```php
     * db()->buildSchemaTable('_schema');
     *
     * // Query all columns for a table
     * $columns = db()->query("SELECT * FROM _schema WHERE table_name = ? AND type = 'column'", ['users']);
     *
     * // List all tables
     * $tables = db()->queryColumn('SELECT DISTINCT table_name FROM _schema');
     *
     * // Find all indexes
     * $indexes = db()->query("SELECT * FROM _schema WHERE type IN ('primary', 'unique', 'index')");
     *
     * // Find primary key columns for a table
     * $pk = db()->queryOne("SELECT extra FROM _schema WHERE table_name = ? AND type = 'primary'", ['users']);
     * ```
     *
     * @param string $tableName Name for the temporary schema table
     */
    public function buildSchemaTable(string $tableName): void;
}
