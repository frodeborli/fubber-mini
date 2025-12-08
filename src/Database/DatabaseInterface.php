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
     * Execute a SELECT query and return results as ResultSet
     *
     * Returns a ResultSet that can be iterated, converted to array, or JSON serialized.
     * Supports hydration to entity classes via withEntityClass() or withHydrator().
     *
     * Example:
     * ```php
     * // Iterate
     * foreach (db()->query('SELECT * FROM users WHERE active = ?', [1]) as $row) {
     *     echo $row['name'];
     * }
     *
     * // Get all as array
     * $users = db()->query('SELECT * FROM users')->toArray();
     *
     * // JSON serialize (e.g., in route handlers)
     * return db()->query('SELECT * FROM users');
     *
     * // With hydration
     * $users = db()->query('SELECT * FROM users')
     *     ->withEntityClass(User::class)
     *     ->toArray();
     * ```
     *
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Parameters to bind to the query
     * @return ResultSetInterface<array>
     */
    public function query(string $sql, array $params = []): ResultSetInterface;

    /**
     * Create a PartialQuery for composable query building
     *
     * Returns an immutable query builder that can be further refined with
     * WHERE clauses, ORDER BY, LIMIT, etc. Use this when you need to:
     * - Build queries compositionally
     * - Reuse query scopes
     * - Use delete() or update() with composed conditions
     *
     * For simple table queries:
     * ```php
     * db()->partialQuery('users')->eq('active', 1)->limit(10)
     * ```
     *
     * For complex base SQL (JOINs, subqueries, etc):
     * ```php
     * db()->partialQuery('posts', 'SELECT p.* FROM posts p JOIN users u ON u.id = p.user_id')
     *     ->eq('u.active', 1)
     * ```
     *
     * @param string $table Table name (required for delete/update operations)
     * @param string|null $sql Optional base SELECT query (if null, selects from $table)
     * @param array $params Parameters for placeholders in base SQL
     * @return PartialQuery<array> Immutable partial query
     */
    public function partialQuery(string $table, ?string $sql = null, array $params = []): PartialQuery;

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
     * Supports nested transactions by maintaining a transaction depth counter.
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
     * @throws \Exception If the transaction fails or the closure throws
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
}
