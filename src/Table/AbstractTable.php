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

    /** Maximum rows to buffer optimistically during iteration */
    protected const OPTIMISTIC_BUFFER_COUNT = 1000;

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

    /** @var array<int|string, object>|null Cached rows for small result sets */
    protected ?array $cachedRows = null;

    /** @var int Cache version when rows were cached (for mutation tracking) */
    protected int $cacheVersion = 0;

    /** @var bool Whether optimistic buffering was disabled (result set too large) */
    protected bool $bufferingDisabled = false;

    /** @var array<string, true>|null Lazy membership index for has() */
    protected ?array $membershipIndex = null;

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
     * Clear cached values on clone (immutable operations clone)
     */
    public function __clone()
    {
        $this->cachedCount = null;
        $this->cachedExists = null;
        $this->cachedRows = null;
        $this->bufferingDisabled = false;
        $this->membershipIndex = null;
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
    public function or(Predicate ...$predicates): TableInterface
    {
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
     * Uses indexed columns when available to avoid full table scans.
     * Falls back to cached rows or iteration for non-indexed lookups.
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

        // Short-circuit: try direct lookup in cached rows via primary key
        // Note: rowid may or may not match primary key, so only trust positive matches
        if ($this->cachedRows !== null) {
            $pk = $this->getPrimaryKeyColumn();
            if ($pk !== null && isset($cols[$pk->name])) {
                $pkValue = $memberValues[$pk->name];
                if ($pkValue !== null && isset($this->cachedRows[$pkValue])) {
                    $cachedRow = $this->cachedRows[$pkValue];
                    // Verify all columns match
                    $matches = true;
                    foreach ($memberValues as $col => $val) {
                        if (($cachedRow->$col ?? null) !== $val) {
                            $matches = false;
                            break;
                        }
                    }
                    if ($matches) {
                        return true;
                    }
                }
                // Don't return false - rowid might not match PK, fall through
            }
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

        // Build membership key from normalized values
        $colNames = array_keys($cols);
        $targetKey = $this->membershipKeyFromValues($memberValues);

        // No unique index - fall back to membership index for small/cached tables
        if ($this->membershipIndex !== null) {
            return isset($this->membershipIndex[$targetKey]);
        }

        // Build index from cached rows if available
        if ($this->cachedRows !== null) {
            $this->membershipIndex = [];
            foreach ($this->cachedRows as $row) {
                $this->membershipIndex[$this->membershipKey($row, $colNames)] = true;
            }
            return isset($this->membershipIndex[$targetKey]);
        }

        // No cache, no index - iterate and search (also builds cache for small tables)
        foreach ($this as $row) {
            if ($this->membershipKey($row, $colNames) === $targetKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate membership key from pre-extracted values array
     */
    private function membershipKeyFromValues(array $values): string
    {
        $parts = [];
        foreach ($values as $val) {
            $parts[] = ($val === null ? 'n:' : 's:') . $val;
        }
        return implode("\x00", $parts);
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
     * Uses optimistic buffering for small result sets (≤1000 rows):
     * - First iteration buffers rows while yielding them
     * - Subsequent iterations yield from cache (no re-execution)
     * - Large result sets disable buffering to avoid memory issues
     *
     * Also memoizes count after full iteration for O(1) count() calls.
     *
     * @return Traversable<int|string, object>
     */
    final public function getIterator(): Traversable
    {
        // If we have cached rows from a previous iteration, yield from them
        // But only if the cache is still valid (no mutations since caching)
        if ($this->cachedRows !== null && $this->cacheVersion === $this->getDataVersion()) {
            yield from $this->cachedRows;
            return;
        }

        // Invalidate stale cache
        if ($this->cachedRows !== null) {
            $this->cachedRows = null;
        }

        $visibleCols = $this->getColumns();
        $buffer = $this->bufferingDisabled ? null : [];
        $count = 0;

        foreach ($this->materialize() as $id => $row) {
            $projected = (object) array_intersect_key((array) $row, $visibleCols);

            // Buffer if under limit
            if ($buffer !== null) {
                if ($count < static::OPTIMISTIC_BUFFER_COUNT) {
                    $buffer[$id] = $projected;
                } else {
                    // Exceeded limit - stop buffering
                    $this->bufferingDisabled = true;
                    $buffer = null;
                }
            }

            yield $id => $projected;
            $count++;
        }

        // After full iteration, cache small result sets
        if ($buffer !== null) {
            $this->cachedRows = $buffer;
            $this->cacheVersion = $this->getDataVersion();
        }

        // Memoize count for subsequent count() calls
        if ($this->cachedCount === null) {
            $this->cachedCount = $count;
            $this->cachedExists = $count > 0;
        }
    }

    /**
     * Get current data version for cache invalidation
     *
     * Subclasses with mutable underlying data should override this
     * to return a version number that changes when data is modified.
     *
     * @return int Current data version (0 = immutable/never changes)
     */
    protected function getDataVersion(): int
    {
        return 0;
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
     * Default implementation checks cached rows first, then iterates.
     * Subclasses should override for O(1) lookups when possible.
     */
    public function load(string|int $rowId): ?object
    {
        // Check cached rows first
        if ($this->cachedRows !== null && $this->cacheVersion === $this->getDataVersion()) {
            if (isset($this->cachedRows[$rowId])) {
                return $this->cachedRows[$rowId];
            }
            // If cache is complete (not disabled), row doesn't exist
            if (!$this->bufferingDisabled) {
                return null;
            }
        }

        // Fall back to iteration
        foreach ($this as $id => $row) {
            if ($id === $rowId) {
                return $row;
            }
        }

        return null;
    }
}
