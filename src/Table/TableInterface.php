<?php

namespace mini\Table;

use Countable;
use IteratorAggregate;

/**
 * Interface for tabular data access with filtering, ordering, and pagination
 *
 * Implementations MUST be immutable - each method returns a new instance
 * with the constraint applied, leaving the original unchanged:
 *
 * ```php
 * $all = $table;
 * $active = $table->eq('status', 'active');  // $all unchanged
 * $sorted = $active->order('name');           // $active unchanged
 * ```
 *
 * This enables safe composition and reuse of filtered views.
 *
 * Iteration MUST yield row ID as key and row data as stdClass:
 *
 * ```php
 * foreach ($table as $rowId => $row) {
 *     // $rowId: int|string unique identifier
 *     // $row: stdClass with column properties
 *     echo $row->name;
 * }
 * ```
 *
 * Using stdClass (not array) ensures column names are always explicit,
 * which is required for SetInterface::has() with composite keys.
 *
 * Row IDs are required for UPDATE/DELETE operations and for deduplication
 * when merging results (e.g., OR clauses via union()).
 *
 * TableInterface extends SetInterface, enabling tables to be used as
 * subqueries in IN clauses:
 *
 * ```php
 * $activeUserIds = $users->eq('status', 'active')->columns('id');
 * $orders->in('user_id', $activeUserIds);
 * ```
 *
 * @extends IteratorAggregate<int|string, stdClass>
 */
interface TableInterface extends SetInterface, IteratorAggregate, Countable
{
    /**
     * Get column definitions for this table (SetInterface method)
     *
     * Returns ColumnDef objects keyed by column name, with index metadata
     * for query optimization.
     *
     * ```php
     * $cols = $table->getColumns();
     * $names = array_keys($cols);           // ['id', 'name', 'email']
     * $idIndex = $cols['id']->index;        // IndexType::Primary
     * $canSort = $cols['name']->canOrder(['name']);  // true if indexed
     * ```
     *
     * @return array<string, ColumnDef> Column name => ColumnDef
     */
    public function getColumns(): array;

    /**
     * Filter rows where column equals value (NULL uses IS NULL semantics)
     */
    public function eq(string $column, int|float|string|null $value): TableInterface;

    /**
     * Filter rows where column is less than value
     */
    public function lt(string $column, int|float|string $value): TableInterface;

    /**
     * Filter rows where column is less than or equal to value
     */
    public function lte(string $column, int|float|string $value): TableInterface;

    /**
     * Filter rows where column is greater than value
     */
    public function gt(string $column, int|float|string $value): TableInterface;

    /**
     * Filter rows where column is greater than or equal to value
     */
    public function gte(string $column, int|float|string $value): TableInterface;

    /**
     * Filter rows where column value is in the given set
     *
     * The set can be an in-memory Set or another TableInterface (subquery):
     *
     * ```php
     * // In-memory set
     * $table->in('status', new Set('status', ['active', 'pending']));
     *
     * // Subquery
     * $userIds = $orders->eq('status', 'shipped')->columns('user_id');
     * $users->in('id', $userIds);
     * ```
     */
    public function in(string $column, SetInterface $values): TableInterface;

    /**
     * Filter rows where column matches a LIKE pattern
     *
     * Supports SQL LIKE wildcards:
     * - `%` matches any sequence of characters
     * - `_` matches any single character
     *
     * ```php
     * $table->like('name', 'John%');     // Starts with "John"
     * $table->like('email', '%@gmail.com'); // Ends with "@gmail.com"
     * $table->like('code', 'A_B');       // "A" + any char + "B"
     * ```
     */
    public function like(string $column, string $pattern): TableInterface;

    /**
     * Return rows that are in this table OR the other table (set union)
     *
     * Rows are deduplicated by row ID. For OR conditions:
     *
     * ```php
     * // WHERE status = 'active' OR status = 'pending'
     * $table->eq('status', 'active')->union($table->eq('status', 'pending'))
     * ```
     */
    public function union(TableInterface $other): TableInterface;

    /**
     * Filter rows matching any of the given predicates (OR semantics)
     *
     * Each predicate is a filter chain built on a Predicate table:
     *
     * ```php
     * $p = Predicate::from($users);
     *
     * // WHERE status = 'active' OR status = 'pending'
     * $users->or($p->eq('status', 'active'), $p->eq('status', 'pending'));
     *
     * // WHERE (age < 18) OR (age >= 65 AND status = 'retired')
     * $users->or(
     *     $p->lt('age', 18),
     *     $p->gte('age', 65)->eq('status', 'retired')
     * );
     * ```
     */
    public function or(TableInterface ...$predicates): TableInterface;

    /**
     * Return rows that are in this table but NOT in the other set (set difference)
     *
     * Enables all negation operations:
     *
     * ```php
     * // WHERE status != 'inactive'
     * $table->except($table->eq('status', 'inactive'))
     *
     * // WHERE id NOT IN (1, 2, 3)
     * $table->columns('id')->except(new Set('id', [1, 2, 3]))
     *
     * // WHERE name IS NOT NULL
     * $table->except($table->eq('name', null))
     *
     * // INTERSECT (A AND B) = A - (A - B)
     * $a->except($a->except($b))
     * ```
     */
    public function except(SetInterface $other): TableInterface;

    /**
     * Project to specific columns
     *
     * Returns a table with only the specified columns. When used with
     * a single column, the result can be used as a SetInterface for IN clauses.
     *
     * ```php
     * $table->columns('id', 'name');           // Two columns
     * $table->columns('id');                    // Single column - usable as Set
     * $table->columns('org_id', 'user_id');    // Composite key
     * ```
     */
    public function columns(string ...$columns): TableInterface;

    /**
     * Check if value(s) exist in the table's projected columns (SetInterface method)
     *
     * The member must have properties matching getColumns():
     *
     * ```php
     * $table->columns('id')->has((object)['id' => 123]);
     * $table->columns('a', 'b')->has((object)['a' => 1, 'b' => 2]);
     * ```
     */
    public function has(object $member): bool;

    /**
     * Set ordering (overwrites previous)
     *
     * @param string|null $spec Column name(s), optionally suffixed with " ASC" or " DESC"
     *                          Multiple columns: "name ASC, created_at DESC"
     *                          Empty string or null clears ordering
     */
    public function order(?string $spec): TableInterface;

    /**
     * Set maximum number of rows to return (overwrites previous)
     */
    public function limit(int $n): TableInterface;

    /**
     * Set number of rows to skip (overwrites previous)
     */
    public function offset(int $n): TableInterface;

    /**
     * Get current limit (null if unlimited)
     */
    public function getLimit(): ?int;

    /**
     * Get current offset (0 if not set)
     */
    public function getOffset(): int;

    /**
     * Check if the table has any rows
     *
     * Implementations may optimize this (e.g., SELECT EXISTS(...) for databases).
     * Default implementation uses limit(1)->count() > 0.
     *
     * ```php
     * if ($table->eq('status', 'active')->exists()) {
     *     // At least one active row
     * }
     * ```
     */
    public function exists(): bool;
}
