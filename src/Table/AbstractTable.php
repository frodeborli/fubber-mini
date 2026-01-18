<?php

namespace mini\Table;

use Closure;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\IndexType;
use mini\Table\Types\Operator;
use mini\Table\Utility\EmptyTable;
use mini\Table\Utility\TablePropertiesTrait;
use mini\Table\Wrappers\AliasTable;
use mini\Table\Wrappers\DistinctTable;
use mini\Table\Wrappers\ExceptTable;
use mini\Table\Wrappers\UnionTable;
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
    use TablePropertiesTrait;

    /** @var array<string, ColumnDef> All columns in the table */
    private readonly array $columnDefs;

    /** @var string[] Column names available for output (empty = all) */
    private array $visibleColumns = [];

    /** @var Closure(string, string): int|null Custom compare function, null = use default */
    protected ?Closure $compareFn = null;

    protected ?int $limit = null;
    protected int $offset = 0;

    /** @var ColumnDef|false|null Cached primary key column (null=not checked, false=none found) */
    private ColumnDef|false|null $primaryKeyColumn = null;

    public function __construct(ColumnDef ...$columns)
    {
        $defs = [];
        foreach ($columns as $col) {
            $defs[$col->name] = $col;
        }
        $this->columnDefs = $defs;
    }

    /**
     * Hook for subclasses to customize clone behavior
     */
    public function __clone()
    {
        // Default: nothing to reset
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

        return static fn(string $a, string $b): int => $a === $b ? 0 : (\mini\collator()->compare($a, $b) ?: 0);
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

    public function withAlias(?string $tableAlias = null, array $columnAliases = []): TableInterface
    {
        return new AliasTable($this, $tableAlias, $columnAliases);
    }

    /**
     * Get the current table alias (null if not set)
     */
    public function getTableAlias(): ?string
    {
        return $this->getProperty('alias');
    }

    public function union(TableInterface $other): TableInterface
    {
        return new UnionTable($this, $other);
    }

    public function except(SetInterface $other): TableInterface
    {
        return new ExceptTable($this, $other);
    }

    public function distinct(): TableInterface
    {
        return new DistinctTable($this);
    }

    /**
     * Filter rows matching any of the given predicates (OR semantics)
     *
     * ```php
     * // WHERE status = 'active' OR status = 'pending'
     * $users->or(
     *     Predicate::eq('status', 'active'),
     *     Predicate::eq('status', 'pending')
     * );
     *
     * // WHERE (age < 18) OR (age >= 65 AND status = 'retired')
     * $users->or(
     *     Predicate::lt('age', 18),
     *     Predicate::gte('age', 65)->andEq('status', 'retired')
     * );
     * ```
     */
    public function or(Predicate $a, Predicate $b, Predicate ...$more): TableInterface
    {
        $predicates = [$a, $b, ...$more];

        // Filter out empty predicates (they match nothing)
        $predicates = array_values(array_filter(
            $predicates,
            fn($p) => !$p->isEmpty()
        ));

        // No predicates → nothing matches
        if (empty($predicates)) {
            return EmptyTable::from($this);
        }

        // Single predicate → apply directly without union overhead
        if (count($predicates) === 1) {
            return $this->applyPredicate($predicates[0]);
        }

        // Multiple predicates → union branches
        $result = $this->applyPredicate($predicates[0]);
        for ($i = 1; $i < count($predicates); $i++) {
            $branch = $this->applyPredicate($predicates[$i]);
            $result = $result->union($branch);
        }

        return $result;
    }

    /**
     * Apply a Predicate to this table
     *
     * Converts Predicate conditions to table filter calls.
     */
    private function applyPredicate(Predicate $predicate): TableInterface
    {
        $result = $this;

        foreach ($predicate->getConditions() as $cond) {
            $col = $cond['column'];
            $op = $cond['operator'];
            $val = $cond['value'];

            $result = match ($op) {
                Operator::Eq => $result->eq($col, $val),
                Operator::Lt => $result->lt($col, $val),
                Operator::Lte => $result->lte($col, $val),
                Operator::Gt => $result->gt($col, $val),
                Operator::Gte => $result->gte($col, $val),
                Operator::In => $result->in($col, $val),
                Operator::Like => $result->like($col, $val),
            };
        }

        return $result;
    }

    public function exists(): bool
    {
        return $this->limit(1)->count() > 0;
    }

    /**
     * Check if value(s) exist in the table's projected columns
     *
     * Uses indexed columns when available to avoid full table scans.
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

        // Normalize member to array for faster comparison
        $memberValues = [];
        foreach ($cols as $col => $def) {
            $memberValues[$col] = $member->$col ?? null;
        }

        // Try to find a unique index we can query directly
        $uniqueCol = $this->findUniqueIndexColumn($cols);
        if ($uniqueCol !== null) {
            $query = $this->eq($uniqueCol, $memberValues[$uniqueCol]);

            // Apply remaining column filters
            foreach ($memberValues as $col => $val) {
                if ($col !== $uniqueCol) {
                    $query = $query->eq($col, $val);
                }
            }

            return $query->exists();
        }

        // No unique index - iterate and search
        foreach ($this as $row) {
            $matches = true;
            foreach ($memberValues as $col => $val) {
                if (($row->$col ?? null) !== $val) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the primary key column definition (cached)
     */
    protected function getPrimaryKeyColumn(): ?ColumnDef
    {
        if ($this->primaryKeyColumn === null) {
            $this->primaryKeyColumn = false;
            foreach ($this->columnDefs as $col) {
                if ($col->index === IndexType::Primary) {
                    $this->primaryKeyColumn = $col;
                    break;
                }
            }
        }
        return $this->primaryKeyColumn ?: null;
    }

    /**
     * Find a column with a unique index (Primary or Unique)
     *
     * @return string|null Column name if found, null otherwise
     */
    private function findUniqueIndexColumn(array $cols): ?string
    {
        foreach ($cols as $col => $def) {
            if ($def->index === IndexType::Primary || $def->index === IndexType::Unique) {
                return $col;
            }
        }
        return null;
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
        // Fast path: no column projection needed
        if (empty($this->visibleColumns)) {
            yield from $this->materialize();
            return;
        }

        // Slow path: project to visible columns only
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
        // Preserve the order from visibleColumns, not columnDefs
        $result = [];
        foreach ($this->visibleColumns as $col) {
            if (isset($this->columnDefs[$col])) {
                $result[$col] = $this->columnDefs[$col];
            }
        }
        return $result;
    }

    /**
     * Get all column definitions regardless of projection
     *
     * Used by wrappers that need to filter/sort on columns not in the output.
     *
     * @return array<string, ColumnDef>
     */
    public function getAllColumns(): array
    {
        return $this->columnDefs;
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

    /**
     * Load a single row by its row ID
     *
     * Default implementation iterates to find the row.
     * Subclasses should override for O(1) lookups when possible.
     */
    public function load(string|int $rowId): ?object
    {
        foreach ($this as $id => $row) {
            if ($id === $rowId) {
                return $row;
            }
        }
        return null;
    }
}
