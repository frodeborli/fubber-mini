<?php

namespace mini\Table;

use Closure;
use Traversable;

/**
 * Base class for all table implementations
 *
 * Provides:
 * - Centralized order() parsing into OrderDef[]
 * - Centralized limit/offset handling
 * - String collation function for sorting (locale-aware by default)
 * - Default union() and except() returning wrapper types
 */
abstract class AbstractTable implements TableInterface
{
    /** @var array<string, ColumnDef> All columns in the table */
    private readonly array $columnDefs;

    /** @var string[] Column names available for output (empty = all) */
    private array $visibleColumns = [];

    /** @var Closure(string, string): int|null Custom compare function, null = use default */
    protected ?Closure $compareFn = null;

    protected ?int $limit = null;
    protected int $offset = 0;

    /** @var int|null Cached count result (cleared on clone) */
    protected ?int $cachedCount = null;

    /** @var bool|null Cached exists result (cleared on clone) */
    protected ?bool $cachedExists = null;

    /** @var array<string, true>|null Lazy membership index for has() */
    protected ?array $membershipIndex = null;

    /** @var bool Whether membershipIndex was built using row keys */
    private bool $membershipIndexUsesRowKeys = false;

    public function __construct(ColumnDef ...$columns)
    {
        $defs = [];
        foreach ($columns as $col) {
            $defs[$col->name] = $col;
        }
        $this->columnDefs = $defs;
    }

    /**
     * Clear cached values on clone (immutable operations clone)
     */
    public function __clone()
    {
        $this->cachedCount = null;
        $this->cachedExists = null;
        $this->membershipIndex = null;
        $this->membershipIndexUsesRowKeys = false;
    }

    /**
     * Get column name(s) that the row key represents
     *
     * If non-empty, the row keys from iteration are the values of these columns.
     * This enables optimizations when checking membership on exactly these columns.
     *
     * @return string[] Column names that form the row key (typically primary key)
     */
    public function getRowKeyColumns(): array
    {
        return [];
    }

    /**
     * Get the string comparison function for sorting
     *
     * Used by SortedTable for string column comparisons. By default uses
     * the application's Collator service (via mini\collator()) when available,
     * falling back to binary comparison (<=>) otherwise.
     *
     * @return \Closure(string, string): int
     */
    protected function getCompareFn(): Closure
    {
        if ($this->compareFn !== null) {
            return $this->compareFn;
        }

        // Use locale-aware collation via mini\collator() service
        if (function_exists('mini\\collator')) {
            $collator = \mini\collator();
            return fn(string $a, string $b): int => $collator->compare($a, $b) ?: 0;
        }

        // Fallback to binary comparison
        return fn(string $a, string $b): int => $a <=> $b;
    }

    /**
     * Create a copy with a custom comparison function for string sorting
     *
     * @param Closure(string, string): int $fn Comparison function
     */
    public function withCompareFn(Closure $fn): static
    {
        $c = clone $this;
        $c->compareFn = $fn;
        return $c;
    }

    /**
     * Apply ordering to the table
     *
     * Implementations must choose how to handle ordering:
     * - Store locally and apply in materialize() (e.g., SQL-backed tables push to DB)
     * - Return a SortedTable wrapper for in-memory sorting
     *
     * @param string|null $spec Column name(s), optionally suffixed with " ASC" or " DESC"
     *                          Multiple columns: "name ASC, created_at DESC"
     *                          Empty string or null clears ordering
     */
    abstract public function order(?string $spec): TableInterface;

    public function limit(?int $n): TableInterface
    {
        if ($this->limit === $n) {
            return $this;            
        }
        $c = clone $this;
        $c->limit = $n;
        return $c;
    }

    public function offset(int $n): TableInterface
    {
        if ($this->offset === $n) {
            return $this;
        }
        $c = clone $this;
        $c->offset = $n;
        return $c;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function union(TableInterface $other): TableInterface
    {
        return new UnionTable($this, $other);
    }

    public function except(SetInterface $other): TableInterface
    {
        return new ExceptTable($this, $other);
    }

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
    public function or(TableInterface ...$predicates): TableInterface
    {
        // If any predicate is a bare Predicate (matches everything), OR is redundant
        foreach ($predicates as $p) {
            if ($p instanceof Predicate) {
                return $this;
            }
        }

        // Filter out EmptyTable predicates (they match nothing)
        $predicates = array_values(array_filter(
            $predicates,
            fn($p) => !$p instanceof EmptyTable
        ));

        // No predicates → nothing matches
        if (empty($predicates)) {
            return EmptyTable::from($this);
        }

        // Single predicate → apply directly without union overhead
        if (count($predicates) === 1) {
            return $this->applyPredicateChain($predicates[0], $this);
        }

        // Multiple predicates → union branches
        $result = $this->applyPredicateChain($predicates[0], $this);
        for ($i = 1; $i < count($predicates); $i++) {
            $branch = $this->applyPredicateChain($predicates[$i], $this);
            $result = $result->union($branch);
        }

        return $result;
    }

    /**
     * Apply a predicate chain to a target table
     *
     * Recursively unwraps the predicate chain and replays operations on target.
     */
    private function applyPredicateChain(TableInterface $predicate, TableInterface $target): TableInterface
    {
        // Base case: hit the Predicate root
        if ($predicate instanceof Predicate) {
            return $target;
        }

        // Recursive: unwrap and replay
        if ($predicate instanceof AbstractTableWrapper) {
            $inner = $this->applyPredicateChain($predicate->getSource(), $target);

            if ($predicate instanceof FilteredTable) {
                $col = $predicate->getFilterColumn();
                $val = $predicate->getFilterValue();

                return match ($predicate->getFilterOperator()) {
                    Operator::Eq => $inner->eq($col, $val),
                    Operator::Lt => $inner->lt($col, $val),
                    Operator::Lte => $inner->lte($col, $val),
                    Operator::Gt => $inner->gt($col, $val),
                    Operator::Gte => $inner->gte($col, $val),
                    Operator::In => $inner->in($col, $val),
                    Operator::Like => $inner->like($col, $val),
                };
            }
        }

        throw new \InvalidArgumentException(
            'Predicate chain contains unsupported wrapper: ' . get_class($predicate)
            . '. Only filter operations (eq, lt, gt, etc.) are allowed.'
        );
    }

    public function exists(): bool
    {
        if ($this->cachedExists !== null) {
            return $this->cachedExists;
        }
        // If count is already cached, use it
        if ($this->cachedCount !== null) {
            return $this->cachedExists = $this->cachedCount > 0;
        }
        return $this->cachedExists = $this->limit(1)->count() > 0;
    }

    /**
     * Check if value(s) exist in the table's projected columns
     *
     * Builds a membership index lazily on first call for O(1) subsequent lookups.
     * Returns false if the member's properties don't exactly match the table's columns.
     *
     * @param object $member Object with properties matching getColumns()
     */
    public function has(object $member): bool
    {
        $cols = $this->getColumns();
        $memberProps = array_keys((array) $member);

        // Member shape must match table columns exactly
        if (count($cols) !== count($memberProps)) {
            return false;
        }
        foreach ($memberProps as $prop) {
            if (!isset($cols[$prop])) {
                return false;
            }
        }

        // Build membership index lazily (also captures count)
        if ($this->membershipIndex === null) {
            $colNames = array_keys($cols);
            $rowKeyColumns = $this->getRowKeyColumns();

            // Optimization: if checking exactly the row key columns, use row keys directly
            $this->membershipIndexUsesRowKeys = ($colNames === $rowKeyColumns && count($colNames) === 1);

            $this->membershipIndex = [];
            $count = 0;
            foreach ($this as $id => $row) {
                $key = $this->membershipIndexUsesRowKeys ? $id : $this->membershipKey($row, $colNames);
                $this->membershipIndex[$key] = true;
                $count++;
            }
            // Cache count since we iterated anyway
            if ($this->cachedCount === null) {
                $this->cachedCount = $count;
                $this->cachedExists = $count > 0;
            }
        }

        if ($this->membershipIndexUsesRowKeys) {
            // Direct key lookup when checking row key column
            $colName = array_keys($cols)[0];
            return isset($this->membershipIndex[$member->$colName ?? null]);
        }

        return isset($this->membershipIndex[$this->membershipKey($member, array_keys($cols))]);
    }

    /**
     * Generate a membership key for index lookup
     */
    private function membershipKey(object $row, array $colNames): string
    {
        $parts = [];
        foreach ($colNames as $col) {
            $val = $row->$col ?? null;
            // Prefix with type to distinguish null from "null" string
            $parts[] = ($val === null ? 'n:' : 's:') . $val;
        }
        return implode("\x00", $parts);
    }

    /**
     * Materialize function is needed to facilitate AbstractTableWrapper and other logic that
     * might require access to columns that aren't selected for output via TableInterface::columns().
     *
     * @return Traversable<int|string, object>
     */
    abstract protected function materialize(string ...$additionalColumns): Traversable;

    /**
     * Iterate over rows with visible columns only
     *
     * @return Traversable<int|string, object>
     */
    final public function getIterator(): Traversable
    {
        $visibleCols = $this->getColumns();

        foreach ($this->materialize() as $id => $row) {
            yield $id => (object) array_intersect_key((array) $row, $visibleCols);
        }
    }

    /**
     * Get columns available for output
     *
     * @return array<string, ColumnDef>
     */
    public function getColumns(): array
    {
        if (empty($this->visibleColumns)) {
            return $this->columnDefs;
        }
        $result = [];
        foreach ($this->visibleColumns as $name) {
            $result[$name] = $this->columnDefs[$name];
        }
        return $result;
    }

    /**
     * Narrow to specific columns
     *
     * @throws \InvalidArgumentException if column doesn't exist
     */
    public function columns(string ...$columns): TableInterface
    {
        $available = $this->getColumns();
        foreach ($columns as $col) {
            if (!isset($available[$col])) {
                throw new \InvalidArgumentException(
                    "Column '$col' does not exist in table"
                );
            }
        }
        $c = clone $this;
        $c->visibleColumns = $columns;
        return $c;
    }
}
