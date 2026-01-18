<?php

namespace mini\Table;

use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Index\TreapIndex;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Utility\EmptyTable;
use mini\Table\Wrappers\SortedTable;
use Traversable;

/**
 * Pure PHP array-backed in-memory table implementation
 *
 * Unlike InMemoryTable which uses SQLite as a backend, this implementation
 * stores all data in PHP arrays. Useful for benchmarking and environments
 * without SQLite extension.
 *
 * ```php
 * $table = new ArrayTable(
 *     new ColumnDef('id', ColumnType::Int, IndexType::Primary),
 *     new ColumnDef('name', ColumnType::Text),
 * );
 *
 * $table->insert(['id' => 1, 'name' => 'Alice']);
 * ```
 */
class ArrayTable extends AbstractTable implements MutableTableInterface
{
    /** @var array<int, object> All rows indexed by rowid (stored as objects for fast iteration) */
    private array $rows = [];

    /** @var int Auto-increment counter for rowid */
    private int $nextRowId = 1;

    /** @var array<string, TreapIndex> Index: column name => TreapIndex (for primary/unique columns) */
    private array $indexes = [];

    /** @var array{column: string, op: string, value: mixed}[] */
    private array $where = [];

    /** @var OrderDef[] */
    private array $orderBy = [];

    public function __construct(ColumnDef ...$columns)
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('ArrayTable requires at least one column');
        }

        parent::__construct(...$columns);

        // Initialize TreapIndex for all indexed columns
        foreach ($columns as $col) {
            if ($col->index->isIndexed()) {
                $this->indexes[$col->name] = new TreapIndex();
            }
        }
    }

    public function __clone()
    {
        // Keep same row data reference - clones share the data (like InMemoryTable)
    }

    // =========================================================================
    // Mutation methods
    // =========================================================================

    public function insert(array $row): int|string
    {
        $rowId = $this->nextRowId++;

        // Update indexes (TreapIndex uses string keys - pack numeric types for proper ordering)
        foreach ($this->indexes as $col => $index) {
            if (isset($row[$col])) {
                $index->insert($this->toIndexKey($col, $row[$col]), $rowId);
            }
        }

        $this->rows[$rowId] = (object) $row;

        return $rowId;
    }

    public function update(TableInterface $query, array $changes): int
    {
        $this->validateQuery($query);

        $affected = 0;
        foreach ($query as $rowId => $_) {
            // Update indexes for changed indexed columns
            foreach ($this->indexes as $col => $index) {
                if (isset($changes[$col])) {
                    // Remove old index entry
                    $oldValue = $this->rows[$rowId]->$col ?? null;
                    if ($oldValue !== null) {
                        $index->delete($this->toIndexKey($col, $oldValue), $rowId);
                    }
                    // Add new index entry
                    $index->insert($this->toIndexKey($col, $changes[$col]), $rowId);
                }
            }

            // Apply changes
            foreach ($changes as $col => $value) {
                $this->rows[$rowId]->$col = $value;
            }
            $affected++;
        }

        return $affected;
    }

    public function delete(TableInterface $query): int
    {
        $this->validateQuery($query);

        // Collect rowids first to avoid modifying array during iteration
        $rowIds = [];
        foreach ($query as $rowId => $_) {
            $rowIds[] = $rowId;
        }

        foreach ($rowIds as $rowId) {
            // Remove from indexes
            foreach ($this->indexes as $col => $index) {
                $value = $this->rows[$rowId]->$col ?? null;
                if ($value !== null) {
                    $index->delete($this->toIndexKey($col, $value), $rowId);
                }
            }
            unset($this->rows[$rowId]);
        }

        return count($rowIds);
    }

    private function validateQuery(TableInterface $query): void
    {
        if (!$query instanceof self) {
            throw new \InvalidArgumentException(
                'Query must be an ArrayTable derived from this table'
            );
        }
        if ($query->rows !== $this->rows) {
            throw new \InvalidArgumentException(
                'Query must be derived from the same table instance'
            );
        }
    }

    /**
     * Convert a value to an index key based on column type.
     * Uses Index::packInt/packFloat for numeric types to ensure proper strcmp ordering.
     */
    private function toIndexKey(string $column, mixed $value): string
    {
        $cols = $this->getAllColumns();
        $type = $cols[$column]->type ?? ColumnType::Text;

        return match ($type) {
            ColumnType::Int => Index::packInt((int) $value),
            ColumnType::Float, ColumnType::Decimal => Index::packFloat((float) $value),
            default => (string) $value,
        };
    }

    // =========================================================================
    // Filter methods
    // =========================================================================

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => '=', 'value' => $value];
        return $clone;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => '<', 'value' => $value];
        return $clone;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => '<=', 'value' => $value];
        return $clone;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => '>', 'value' => $value];
        return $clone;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => '>=', 'value' => $value];
        return $clone;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        // Materialize the set values
        $members = [];
        foreach ($values as $row) {
            $cols = array_keys($values->getColumns());
            if (count($cols) === 1) {
                $members[] = $row->{$cols[0]};
            }
        }

        if (empty($members)) {
            return EmptyTable::from($this);
        }

        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => 'IN', 'value' => $members];
        return $clone;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $clone = clone $this;
        $clone->where[] = ['column' => $column, 'op' => 'LIKE', 'value' => $pattern];
        return $clone;
    }

    // =========================================================================
    // Ordering
    // =========================================================================

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];

        if (empty($orders)) {
            $clone = clone $this;
            $clone->orderBy = [];
            return $clone;
        }

        // If primary order column is indexed, we can satisfy it via index scan
        $primaryCol = $orders[0]->column;
        if (isset($this->indexes[$primaryCol]) && count($orders) === 1) {
            $clone = clone $this;
            $clone->orderBy = $orders;
            return $clone;
        }

        // Otherwise use SortedTable wrapper
        return new SortedTable($this, ...$orders);
    }

    // =========================================================================
    // Materialization
    // =========================================================================

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $offset = $this->offset;
        $limit = $this->limit;
        $count = 0;
        $yielded = 0;

        // Analyze filters and ordering to find best index strategy
        [$indexCol, $lowBound, $highBound, $includeLow, $includeHigh, $remainingFilters] = $this->planIndexScan();

        // Check if we can use an index
        if ($indexCol !== null && isset($this->indexes[$indexCol])) {
            $index = $this->indexes[$indexCol];

            // Determine iteration direction from ORDER BY
            $reverse = false;
            if (!empty($this->orderBy) && $this->orderBy[0]->column === $indexCol) {
                $reverse = !$this->orderBy[0]->asc;
            }

            // Stream from index - yields rowIds in sorted order
            foreach ($index->range($lowBound, $highBound, $reverse) as $rowId) {
                if (!isset($this->rows[$rowId])) {
                    continue;
                }
                $row = $this->rows[$rowId];

                // Apply remaining (non-indexed) filters only
                if (!$this->matchesFilterList($row, $rowId, $remainingFilters)) {
                    continue;
                }

                if ($offset > 0 && $count < $offset) {
                    $count++;
                    continue;
                }

                if ($limit !== null && $yielded >= $limit) {
                    return;
                }

                yield $rowId => $row;
                $count++;
                $yielded++;
            }
            return;
        }

        // Full scan path - no usable index
        // Note: orderBy is only set when it can be satisfied by index,
        // otherwise order() returns SortedTable wrapper
        foreach ($this->rows as $rowId => $row) {
            if (!$this->matchesFilters($row, $rowId)) {
                continue;
            }

            if ($offset > 0 && $count < $offset) {
                $count++;
                continue;
            }

            if ($limit !== null && $yielded >= $limit) {
                break;
            }

            yield $rowId => $row;
            $count++;
            $yielded++;
        }
    }

    /**
     * Plan an index scan based on filters and ordering
     *
     * Returns: [indexCol, lowBound, highBound, includeLow, includeHigh, remainingFilters]
     * - indexCol: column to use for index scan, or null for full scan
     * - lowBound/highBound: range bounds (null = unbounded)
     * - includeLow/includeHigh: whether bounds are inclusive
     * - remainingFilters: filters that couldn't use the index
     */
    private function planIndexScan(): array
    {
        $indexCol = null;
        $lowBound = null;
        $highBound = null;
        $includeLow = false;
        $includeHigh = false;
        $remainingFilters = [];

        // Prefer ORDER BY column if indexed (gives us sorted output for free)
        if (!empty($this->orderBy)) {
            $orderCol = $this->orderBy[0]->column;
            if (isset($this->indexes[$orderCol])) {
                $indexCol = $orderCol;
            }
        }

        // Process filters to find bounds and remaining filters
        foreach ($this->where as $filter) {
            $column = $filter['column'];
            $op = $filter['op'];
            $value = $filter['value'];

            // If this filter is on an indexed column
            if (isset($this->indexes[$column])) {
                // Prefer eq filter (most selective) or match ORDER BY column
                if ($indexCol === null || $column === $indexCol || $op === '=') {
                    if ($op === '=' && $value !== null) {
                        // Exact match - set both bounds to same value
                        $indexCol = $column;
                        $indexKey = $this->toIndexKey($column, $value);
                        $lowBound = $indexKey;
                        $highBound = $indexKey;
                        // Don't add to remaining - already handled by bounds
                        continue;
                    } elseif (($indexCol === null || $column === $indexCol) && $value !== null) {
                        // Range filter - use this column for index scan
                        $indexCol = $column;
                        // TreapIndex uses inclusive bounds, so convert exclusive to inclusive:
                        // > X becomes >= X+1 (for integers) or add to remainingFilters
                        // < X becomes <= X-1 (for integers) or add to remainingFilters
                        $cols = $this->getAllColumns();
                        $type = $cols[$column]->type ?? ColumnType::Text;

                        if ($op === '>' || $op === '>=') {
                            if ($op === '>' && $type === ColumnType::Int) {
                                // Exclusive: > X is same as >= X+1
                                $adjustedKey = $this->toIndexKey($column, (int) $value + 1);
                            } else {
                                $adjustedKey = $this->toIndexKey($column, $value);
                            }
                            if ($lowBound === null || $adjustedKey > $lowBound) {
                                $lowBound = $adjustedKey;
                            }
                            // For non-integer exclusive bounds, add to remainingFilters
                            if ($op === '>' && $type !== ColumnType::Int) {
                                $remainingFilters[] = $filter;
                            }
                            continue;
                        } elseif ($op === '<' || $op === '<=') {
                            if ($op === '<' && $type === ColumnType::Int) {
                                // Exclusive: < X is same as <= X-1
                                $adjustedKey = $this->toIndexKey($column, (int) $value - 1);
                            } else {
                                $adjustedKey = $this->toIndexKey($column, $value);
                            }
                            if ($highBound === null || $adjustedKey < $highBound) {
                                $highBound = $adjustedKey;
                            }
                            // For non-integer exclusive bounds, add to remainingFilters
                            if ($op === '<' && $type !== ColumnType::Int) {
                                $remainingFilters[] = $filter;
                            }
                            continue;
                        }
                    }
                }
            }

            // Filter couldn't use index
            $remainingFilters[] = $filter;
        }

        return [$indexCol, $lowBound, $highBound, $includeLow, $includeHigh, $remainingFilters];
    }

    /**
     * Check if row matches a specific list of filters
     */
    private function matchesFilterList(object $row, int $rowId, array $filters): bool
    {
        foreach ($filters as $filter) {
            $column = $filter['column'];
            $op = $filter['op'];
            $filterValue = $filter['value'];

            if ($column === '_rowid_') {
                $rowValue = $rowId;
            } else {
                $rowValue = $row->$column ?? null;
            }

            $matches = match ($op) {
                '=' => $this->compareEqual($rowValue, $filterValue),
                '<' => $this->compareLt($rowValue, $filterValue),
                '<=' => $this->compareLte($rowValue, $filterValue),
                '>' => $this->compareGt($rowValue, $filterValue),
                '>=' => $this->compareGte($rowValue, $filterValue),
                'IN' => in_array($rowValue, $filterValue, false),
                'LIKE' => $this->compareLike($rowValue, $filterValue),
                default => true,
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    private function matchesFilters(object $row, int $rowId): bool
    {
        foreach ($this->where as $filter) {
            $column = $filter['column'];
            $op = $filter['op'];
            $filterValue = $filter['value'];

            // Special handling for _rowid_ (internal row identifier)
            if ($column === '_rowid_') {
                $rowValue = $rowId;
            } else {
                $rowValue = $row->$column ?? null;
            }

            $matches = match ($op) {
                '=' => $this->compareEqual($rowValue, $filterValue),
                '<' => $this->compareLt($rowValue, $filterValue),
                '<=' => $this->compareLte($rowValue, $filterValue),
                '>' => $this->compareGt($rowValue, $filterValue),
                '>=' => $this->compareGte($rowValue, $filterValue),
                'IN' => in_array($rowValue, $filterValue, false),
                'LIKE' => $this->compareLike($rowValue, $filterValue),
                default => true,
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    private function compareEqual(mixed $a, mixed $b): bool
    {
        // NULL handling: NULL = NULL is true, NULL = anything_else is false
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        // Numeric comparison for numeric strings
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a == (float) $b;
        }
        return $a == $b;
    }

    private function compareLt(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a < (float) $b;
        }
        return $a < $b;
    }

    private function compareLte(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <= (float) $b;
        }
        return $a <= $b;
    }

    private function compareGt(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a > (float) $b;
        }
        return $a > $b;
    }

    private function compareGte(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a >= (float) $b;
        }
        return $a >= $b;
    }

    private function compareLike(mixed $value, string $pattern): bool
    {
        if ($value === null) {
            return false;
        }
        // Convert SQL LIKE pattern to regex
        // % = any characters, _ = single character
        $regex = '/^';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];
            if ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }
        $regex .= '$/is';

        return preg_match($regex, (string) $value) === 1;
    }

    private function sortRows(array $rows): array
    {
        $compareFn = $this->getCompareFn();

        uasort($rows, function ($a, $b) use ($compareFn) {
            foreach ($this->orderBy as $order) {
                $col = $order->column;
                $valA = $a->$col ?? null;
                $valB = $b->$col ?? null;

                // NULL handling: NULLs sort last in ASC, first in DESC
                if ($valA === null && $valB === null) {
                    continue;
                }
                if ($valA === null) {
                    return $order->asc ? 1 : -1;
                }
                if ($valB === null) {
                    return $order->asc ? -1 : 1;
                }

                // Compare values
                if (is_numeric($valA) && is_numeric($valB)) {
                    $cmp = (float) $valA <=> (float) $valB;
                } elseif (is_string($valA) && is_string($valB)) {
                    $cmp = $compareFn($valA, $valB);
                } else {
                    $cmp = $valA <=> $valB;
                }

                if ($cmp !== 0) {
                    return $order->asc ? $cmp : -$cmp;
                }
            }
            return 0;
        });

        return $rows;
    }

    public function count(): int
    {
        $count = 0;
        $offset = $this->offset;
        $limit = $this->limit;
        $skipped = 0;

        foreach ($this->rows as $rowId => $row) {
            if (!$this->matchesFilters($row, $rowId)) {
                continue;
            }

            if ($offset > 0 && $skipped < $offset) {
                $skipped++;
                continue;
            }

            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        return $count;
    }

    public function load(string|int $rowId): ?object
    {
        if (!isset($this->rows[$rowId])) {
            return null;
        }

        $row = $this->rows[$rowId];

        // Check filters
        if (!$this->matchesFilters($row, (int) $rowId)) {
            return null;
        }

        return $row;  // Already an object
    }
}
