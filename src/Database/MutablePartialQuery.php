<?php

namespace mini\Database;

use Closure;
use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use mini\Table\Utility\TablePropertiesTrait;

/**
 * Mutable wrapper for PartialQuery that proxies mutations to the real database
 *
 * Wraps a PartialQuery and executes INSERT/UPDATE/DELETE on the underlying
 * database while enforcing:
 * - Base query constraints (via Predicate matching)
 * - Custom validators for each operation type
 *
 * Primary use case: Register with VirtualDatabase to provide secure, scoped
 * database access for browser APIs.
 *
 * ```php
 * $users = new MutablePartialQuery(
 *     $db->query('SELECT * FROM users WHERE org_id = ?', [$orgId]),
 *     $db
 * );
 *
 * // Add custom validation
 * $users = $users->withInsertValidator(fn($row) =>
 *     isset($row['email']) || throw new Exception('Email required')
 * );
 *
 * // Register with VirtualDatabase for browser access
 * $vdb->registerTable('users', $users);
 * ```
 */
final class MutablePartialQuery implements MutableTableInterface
{
    use TablePropertiesTrait;

    private ?Closure $insertValidator = null;
    private ?Closure $updateValidator = null;
    private ?Closure $deleteValidator = null;

    /**
     * @param PartialQuery $query Base query defining the accessible scope
     * @param DatabaseInterface $db Database for executing mutations
     * @throws \InvalidArgumentException If query is not a single-table query
     */
    public function __construct(
        private PartialQuery $query,
        private DatabaseInterface $db
    ) {
        if (!$query->isSingleTable()) {
            throw new \InvalidArgumentException(
                "MutablePartialQuery requires a single-table query (no JOINs, UNIONs, or complex FROM)"
            );
        }
    }

    /**
     * Add a validator for INSERT operations
     *
     * Validator receives the row being inserted and should throw on validation failure.
     *
     * @param Closure $validator fn(array $row): void
     */
    public function withInsertValidator(Closure $validator): self
    {
        $clone = clone $this;
        $clone->insertValidator = $validator;
        return $clone;
    }

    /**
     * Add a validator for UPDATE operations
     *
     * Validator receives the primary key and new row state.
     *
     * @param Closure $validator fn(array $primaryKey, object $after): void
     */
    public function withUpdateValidator(Closure $validator): self
    {
        $clone = clone $this;
        $clone->updateValidator = $validator;
        return $clone;
    }

    /**
     * Add a validator for DELETE operations
     *
     * Validator receives the primary key of the row being deleted.
     *
     * @param Closure $validator fn(array $primaryKey): void
     */
    public function withDeleteValidator(Closure $validator): self
    {
        $clone = clone $this;
        $clone->deleteValidator = $validator;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // MutableTableInterface: Mutation operations
    // -------------------------------------------------------------------------

    /**
     * Insert a new row
     *
     * 1. Validates row matches ALL query constraints (base SQL WHERE + Predicate)
     * 2. Runs custom insert validator if set
     * 3. Executes INSERT on the real database
     *
     * @throws \RuntimeException If row violates query constraints
     */
    public function insert(array $row): int|string
    {
        // Check row matches ALL query constraints (base SQL WHERE + Predicate)
        if (!$this->query->matches((object)$row)) {
            throw new \RuntimeException("Row violates query constraints");
        }

        // Run custom validator
        if ($this->insertValidator !== null) {
            ($this->insertValidator)($row);
        }

        // Execute INSERT
        return $this->db->insert($this->query->getSourceTable(), $row);
    }

    /**
     * Update rows matching the query
     *
     * Combines base query WHERE with user query WHERE to ensure updates
     * stay within the allowed scope.
     */
    public function update(TableInterface $query, array $changes): int
    {
        if (!$query instanceof self && !$query instanceof PartialQuery) {
            throw new \InvalidArgumentException(
                "update() requires a query derived from this table"
            );
        }

        // Get the user's query
        $userQuery = $query instanceof self ? $query->query : $query;

        // Run update validator if set
        if ($this->updateValidator !== null) {
            $pkCols = $this->getPrimaryKeyColumns();
            // Fetch rows that will be updated
            foreach ($this->applyFilters($userQuery)->columns(...$pkCols) as $row) {
                $pk = [];
                foreach ($pkCols as $col) {
                    $pk[$col] = $row->$col;
                }
                $after = (object)array_merge((array)$row, $changes);
                ($this->updateValidator)($pk, $after);
            }
        }

        // Combine base query with user filters and execute
        $combinedQuery = $this->applyFilters($userQuery);
        return $this->db->update($combinedQuery, $changes);
    }

    /**
     * Delete rows matching the query
     *
     * Combines base query WHERE with user query WHERE to ensure deletes
     * stay within the allowed scope.
     */
    public function delete(TableInterface $query): int
    {
        if (!$query instanceof self && !$query instanceof PartialQuery) {
            throw new \InvalidArgumentException(
                "delete() requires a query derived from this table"
            );
        }

        // Get the user's query
        $userQuery = $query instanceof self ? $query->query : $query;

        // Run delete validator if set
        if ($this->deleteValidator !== null) {
            $pkCols = $this->getPrimaryKeyColumns();
            foreach ($this->applyFilters($userQuery)->columns(...$pkCols) as $row) {
                $pk = [];
                foreach ($pkCols as $col) {
                    $pk[$col] = $row->$col;
                }
                ($this->deleteValidator)($pk);
            }
        }

        // Combine base query with user filters and execute
        $combinedQuery = $this->applyFilters($userQuery);
        return $this->db->delete($combinedQuery);
    }

    /**
     * Get primary key column names
     *
     * Attempts to discover from column metadata, falls back to ['id'].
     *
     * @return string[]
     */
    private function getPrimaryKeyColumns(): array
    {
        $columns = $this->query->getAllColumns();

        if (empty($columns)) {
            return ['id']; // Fallback
        }

        foreach ($columns as $col) {
            if ($col->index === \mini\Table\Types\IndexType::Primary) {
                return $col->getIndexColumns();
            }
        }

        return ['id']; // Fallback
    }

    /**
     * Apply user filters to base query
     */
    private function applyFilters(PartialQuery $userQuery): PartialQuery
    {
        // Get WHERE from user query and apply to base
        $where = $userQuery->getWhere();
        if ($where['sql'] !== '') {
            return $this->query->where($where['sql'], $where['params']);
        }
        return $this->query;
    }

    // -------------------------------------------------------------------------
    // TableInterface: Delegate to underlying PartialQuery
    // -------------------------------------------------------------------------

    public function getIterator(): \Traversable
    {
        return $this->query->getIterator();
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function getColumns(): array
    {
        return $this->query->getColumns();
    }

    public function getAllColumns(): array
    {
        return $this->query->getAllColumns();
    }

    public function eq(string $column, int|float|string|null $value): self
    {
        $clone = clone $this;
        $clone->query = $this->query->eq($column, $value);
        return $clone;
    }

    public function lt(string $column, int|float|string $value): self
    {
        $clone = clone $this;
        $clone->query = $this->query->lt($column, $value);
        return $clone;
    }

    public function lte(string $column, int|float|string $value): self
    {
        $clone = clone $this;
        $clone->query = $this->query->lte($column, $value);
        return $clone;
    }

    public function gt(string $column, int|float|string $value): self
    {
        $clone = clone $this;
        $clone->query = $this->query->gt($column, $value);
        return $clone;
    }

    public function gte(string $column, int|float|string $value): self
    {
        $clone = clone $this;
        $clone->query = $this->query->gte($column, $value);
        return $clone;
    }

    public function in(string $column, SetInterface $values): self
    {
        $clone = clone $this;
        $clone->query = $this->query->in($column, $values);
        return $clone;
    }

    public function like(string $column, string $pattern): self
    {
        $clone = clone $this;
        $clone->query = $this->query->like($column, $pattern);
        return $clone;
    }

    public function union(TableInterface $other): TableInterface
    {
        return $this->query->union($other);
    }

    public function or(Predicate ...$predicates): TableInterface
    {
        return $this->query->or(...$predicates);
    }

    public function except(SetInterface $other): TableInterface
    {
        return $this->query->except($other);
    }

    public function columns(string ...$columns): self
    {
        $clone = clone $this;
        $clone->query = $this->query->columns(...$columns);
        return $clone;
    }

    public function has(object $member): bool
    {
        return $this->query->has($member);
    }

    public function order(?string $spec): self
    {
        $clone = clone $this;
        $clone->query = $this->query->order($spec);
        return $clone;
    }

    public function limit(int $n): self
    {
        $clone = clone $this;
        $clone->query = $this->query->limit($n);
        return $clone;
    }

    public function offset(int $n): self
    {
        $clone = clone $this;
        $clone->query = $this->query->offset($n);
        return $clone;
    }

    public function getLimit(): ?int
    {
        return $this->query->getLimit();
    }

    public function getOffset(): int
    {
        return $this->query->getOffset();
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }

    public function load(int|string $rowId): ?object
    {
        return $this->query->load($rowId);
    }

    public function distinct(): TableInterface
    {
        return $this->query->distinct();
    }

    public function withAlias(?string $tableAlias = null, array $columnAliases = []): TableInterface
    {
        return $this->query->withAlias($tableAlias, $columnAliases);
    }

    // -------------------------------------------------------------------------
    // Additional methods
    // -------------------------------------------------------------------------

    /**
     * Get the underlying PartialQuery
     */
    public function getQuery(): PartialQuery
    {
        return $this->query;
    }

    /**
     * Test if a row matches the filter conditions
     */
    public function matches(object $row): bool
    {
        return $this->query->matches($row);
    }
}
