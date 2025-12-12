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

    public function __construct(ColumnDef ...$columns)
    {
        $defs = [];
        foreach ($columns as $col) {
            $defs[$col->name] = $col;
        }
        $this->columnDefs = $defs;
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

    public function exists(): bool
    {
        return $this->limit(1)->count() > 0;
    }

    /**
     * Check if value(s) exist in the table's projected columns
     *
     * Uses chained eq() filters + exists() to leverage storage engine optimizations.
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

        // Build query by chaining eq() for each column
        $table = $this;
        foreach (array_keys($cols) as $col) {
            $table = $table->eq($col, $member->$col ?? null);
        }

        return $table->exists();
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
