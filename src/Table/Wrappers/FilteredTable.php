<?php
namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\Operator;
use mini\Table\OrderDef;
use mini\Table\Utility\EmptyTable;
use mini\Table\Utility\TablePropertiesTrait;
use Traversable;

/**
 * Filters rows from source table using a column/operator/value condition
 *
 * Unlike AbstractTableWrapper's default pushdown behavior, FilteredTable
 * applies filtering in-memory during materialization. This is used by
 * BarrierTable to filter already-selected rows.
 *
 * ```php
 * new FilteredTable($source, 'status', Operator::Eq, 'active')
 * new FilteredTable($source, 'age', Operator::Gte, 18)
 * new FilteredTable($source, 'id', Operator::In, $setOfIds)
 * ```
 */
class FilteredTable extends AbstractTableWrapper
{
    use TablePropertiesTrait;
    
    private ColumnDef $columnDef;

    public function __construct(
        AbstractTable $source,
        private string $column,
        private Operator $operator,
        private mixed $value,
    ) {
        // Absorb source's limit/offset - we apply them after filtering
        $this->limit = $source->getLimit();
        $this->offset = $source->getOffset();

        // Clear source's limit/offset since we handle it
        if ($this->limit !== null) {
            $source = $source->limit(null);
        }
        if ($this->offset !== 0) {
            $source = $source->offset(0);
        }

        // Validate column exists and cache its definition (check all columns, not just visible)
        $cols = $source->getAllColumns();
        $this->columnDef = $cols[$column] ?? throw new \LogicException("Unknown column '$column'");

        parent::__construct($source);
    }

    // -------------------------------------------------------------------------
    // Accessors for predicate inspection
    // -------------------------------------------------------------------------

    public function getFilterColumn(): string
    {
        return $this->column;
    }

    public function getFilterOperator(): Operator
    {
        return $this->operator;
    }

    public function getFilterValue(): mixed
    {
        return $this->value;
    }

    /**
     * Test if a row matches this filter
     */
    public function test(object $row): bool
    {
        $col = $this->column;
        if (!property_exists($row, $col)) {
            return true; // Open world assumption - missing properties pass
        }
        $rowValue = $row->$col;

        return match ($this->operator) {
            Operator::Eq => $this->testEq($rowValue),
            Operator::Lt => $rowValue !== null && $rowValue < $this->value,
            Operator::Lte => $rowValue !== null && $rowValue <= $this->value,
            Operator::Gt => $rowValue !== null && $rowValue > $this->value,
            Operator::Gte => $rowValue !== null && $rowValue >= $this->value,
            Operator::In => $this->testIn($rowValue),
            Operator::Like => $this->testLike($rowValue),
        };
    }

    private function testEq(mixed $rowValue): bool
    {
        $value = $this->value;
        if ($value === null) {
            return $rowValue === null;
        }
        if (is_numeric($rowValue) && is_numeric($value)) {
            return $rowValue == $value;
        }
        return $rowValue === $value;
    }

    private function testIn(mixed $rowValue): bool
    {
        $member = (object)[$this->column => $rowValue];
        return $this->value->has($member);
    }

    private function testLike(mixed $rowValue): bool
    {
        if ($rowValue === null) {
            return false;
        }
        $regex = '/^' . str_replace(
            ['%', '_'],
            ['.*', '.'],
            preg_quote($this->value, '/')
        ) . '$/i';
        return preg_match($regex, (string)$rowValue) === 1;
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $col = $this->column;
        $allAdditional = array_unique([...$additionalColumns, $col]);
        $source = parent::materialize(...$allAdditional);

        // Select filter strategy once, then apply pagination
        $filtered = match ($this->operator) {
            Operator::Eq => $this->filterEq($source, $col),
            Operator::Lt => $this->filterLt($source, $col),
            Operator::Lte => $this->filterLte($source, $col),
            Operator::Gt => $this->filterGt($source, $col),
            Operator::Gte => $this->filterGte($source, $col),
            Operator::In => $this->filterIn($source, $col),
            Operator::Like => $this->filterLike($source, $col),
        };

        if ($this->getLimit() === null && $this->getOffset() === 0) {
            yield from $filtered;
        } else {
            yield from $this->paginate($filtered);
        }
    }

    private function filterEq(iterable $source, string $col): \Generator
    {
        $value = $this->value;
        if ($value === null) {
            // eq(col, null) implements SQL "col IS NULL"
            foreach ($source as $id => $row) {
                if ($row->$col === null) {
                    yield $id => $row;
                }
            }
        } elseif ($this->columnDef->type->isNumeric()) {
            // Numeric: use == for type coercion (5 == 5.0 is true)
            foreach ($source as $id => $row) {
                if ($row->$col == $value) {
                    yield $id => $row;
                }
            }
        } else {
            // String: use === for exact match
            foreach ($source as $id => $row) {
                if ($row->$col === $value) {
                    yield $id => $row;
                }
            }
        }
    }

    private function filterLt(iterable $source, string $col): \Generator
    {
        $value = $this->value;
        if ($this->columnDef->type->isNumeric()) {
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col < $value) {
                    yield $id => $row;
                }
            }
        } elseif ($this->columnDef->type->shouldUseCollator()) {
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            $cmp = $this->getCompareFn();
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $cmp($row->$col, $value) < 0) {
                    yield $id => $row;
                }
            }
        } else {
            // DateTime, Binary - use binary comparison
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col < $value) {
                    yield $id => $row;
                }
            }
        }
    }

    private function filterLte(iterable $source, string $col): \Generator
    {
        $value = $this->value;
        if ($this->columnDef->type->isNumeric()) {
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col <= $value) {
                    yield $id => $row;
                }
            }
        } elseif ($this->columnDef->type->shouldUseCollator()) {
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            $cmp = $this->getCompareFn();
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $cmp($row->$col, $value) <= 0) {
                    yield $id => $row;
                }
            }
        } else {
            // DateTime, Binary - use binary comparison
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col <= $value) {
                    yield $id => $row;
                }
            }
        }
    }

    private function filterGt(iterable $source, string $col): \Generator
    {
        $value = $this->value;
        if ($this->columnDef->type->isNumeric()) {
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col > $value) {
                    yield $id => $row;
                }
            }
        } elseif ($this->columnDef->type->shouldUseCollator()) {
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            $cmp = $this->getCompareFn();
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $cmp($row->$col, $value) > 0) {
                    yield $id => $row;
                }
            }
        } else {
            // DateTime, Binary - use binary comparison
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col > $value) {
                    yield $id => $row;
                }
            }
        }
    }

    private function filterGte(iterable $source, string $col): \Generator
    {
        $value = $this->value;
        if ($this->columnDef->type->isNumeric()) {
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col >= $value) {
                    yield $id => $row;
                }
            }
        } elseif ($this->columnDef->type->shouldUseCollator()) {
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            $cmp = $this->getCompareFn();
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $cmp($row->$col, $value) >= 0) {
                    yield $id => $row;
                }
            }
        } else {
            // DateTime, Binary - use binary comparison
            if (!is_string($value) && !is_numeric($value)) return;
            $value = (string) $value;
            foreach ($source as $id => $row) {
                if ($row->$col !== null && $row->$col >= $value) {
                    yield $id => $row;
                }
            }
        }
    }

    private function filterIn(iterable $source, string $col): \Generator
    {
        foreach ($source as $id => $row) {
            if ($this->matchesIn($row->$col ?? null)) {
                yield $id => $row;
            }
        }
    }

    private function filterLike(iterable $source, string $col): \Generator
    {
        foreach ($source as $id => $row) {
            if ($this->matchesLike($row->$col ?? null)) {
                yield $id => $row;
            }
        }
    }

    private function paginate(iterable $source): \Generator
    {
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($source as $id => $row) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            yield $id => $row;
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Limit/offset must be stored locally, not pushed to source
    // -------------------------------------------------------------------------

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

    private function matches(mixed $rowValue): bool
    {
        return match ($this->operator) {
            Operator::Eq => $this->compare($rowValue, $this->value) === 0,
            Operator::Lt => $this->compare($rowValue, $this->value) < 0,
            Operator::Lte => $this->compare($rowValue, $this->value) <= 0,
            Operator::Gt => $this->compare($rowValue, $this->value) > 0,
            Operator::Gte => $this->compare($rowValue, $this->value) >= 0,
            Operator::In => $this->matchesIn($rowValue),
            Operator::Like => $this->matchesLike($rowValue),
        };
    }

    /**
     * Compare two values respecting column type
     *
     * - Numeric columns: use <=> (allows int/float coercion)
     * - Text columns: use collator for locale-aware comparison
     * - Binary/DateTime columns: use <=> for raw byte ordering
     */
    private function compare(mixed $left, mixed $right): int
    {
        // Text columns use locale-aware collator
        if ($this->columnDef->type->shouldUseCollator()) {
            if (is_string($left) || is_string($right)) {
                return $this->getCompareFn()((string)$left, (string)$right);
            }
        }
        // All other types (Int, Float, DateTime, Binary) use binary comparison
        return $left <=> $right;
    }

    private function matchesIn(mixed $rowValue): bool
    {
        $set = $this->value;
        if (!$set instanceof SetInterface) {
            throw new \LogicException('IN operator requires SetInterface value');
        }
        $member = (object)[$this->column => $rowValue];
        return $set->has($member);
    }

    private function matchesLike(mixed $rowValue): bool
    {
        if ($rowValue === null) {
            return false;
        }
        // Convert SQL LIKE pattern to regex
        $pattern = $this->value;
        $regex = '/^' . str_replace(
            ['%', '_'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/i';
        return preg_match($regex, (string)$rowValue) === 1;
    }

    public function count(): int
    {
        return iterator_count($this);
    }

    public function has(object $member): bool
    {
        // Short-circuit: if member doesn't match our filter, it's not in the result
        if (!$this->test($member)) {
            return false;
        }
        return parent::has($member);
    }

    public function load(string|int $rowId): ?object
    {
        $row = $this->source->load($rowId);
        if ($row === null) {
            return null;
        }
        // Check if the loaded row passes our filter
        if (!$this->test($row)) {
            return null;
        }
        return $row;
    }

    // -------------------------------------------------------------------------
    // Filter methods - optimize same-column filters, wrap otherwise
    // -------------------------------------------------------------------------

    /**
     * Restore absorbed pagination to a replacement table
     *
     * Used when optimizations bypass this wrapper and return a new table
     * directly on source - we must transfer our absorbed limit/offset.
     */
    private function withPagination(TableInterface $table): TableInterface
    {
        if ($this->limit !== null) {
            $table = $table->limit($this->limit);
        }
        if ($this->offset !== 0) {
            $table = $table->offset($this->offset);
        }
        return $table;
    }

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        if ($column === $this->column) {
            // IN + eq: check if value is in the set (only for simple Sets, not subqueries)
            if ($this->operator === Operator::In && !$this->value instanceof TableInterface) {
                $member = (object)[$column => $value];
                if ($this->value->has($member)) {
                    // Value in set: eq is more specific, use it
                    return $this->withPagination($this->source->eq($column, $value));
                }
                return EmptyTable::from($this);
            }

            // LIKE + eq: check if value matches pattern
            if ($this->operator === Operator::Like) {
                if ($this->matchesLike($value)) {
                    return $this->withPagination($this->source->eq($column, $value));
                }
                return EmptyTable::from($this);
            }

            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $cmp === 0 ? $this : EmptyTable::from($this);
            }
            // Check if current filter allows this eq value
            $compatible = match ($this->operator) {
                Operator::Lt => $cmp < 0,
                Operator::Lte => $cmp <= 0,
                Operator::Gt => $cmp > 0,
                Operator::Gte => $cmp >= 0,
                default => null,
            };
            if ($compatible === true) {
                return $this->withPagination($this->source->eq($column, $value));
            }
            if ($compatible === false) {
                return EmptyTable::from($this);
            }
        }
        return parent::eq($column, $value);
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) < 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Lt) {
                // lt + lt: keep stricter (smaller) bound
                return $cmp < 0 ? $this->withPagination($this->source->lt($column, $value)) : $this;
            }
            if ($this->operator === Operator::Lte) {
                // lte + lt: lt is stricter when value <= existing
                return $cmp <= 0 ? $this->withPagination($this->source->lt($column, $value)) : $this;
            }
            if ($this->operator === Operator::Gt || $this->operator === Operator::Gte) {
                if ($cmp <= 0) {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::lt($column, $value);
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) <= 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Lt || $this->operator === Operator::Lte) {
                return $cmp < 0 ? $this->withPagination($this->source->lte($column, $value)) : $this;
            }
            if ($this->operator === Operator::Gt) {
                if ($cmp <= 0) {
                    return EmptyTable::from($this);
                }
            }
            if ($this->operator === Operator::Gte) {
                if ($cmp > 0) {
                    // valid range, fall through
                } elseif ($cmp === 0) {
                    return $this->withPagination($this->source->eq($column, $value));
                } else {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::lte($column, $value);
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) > 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Gt) {
                // gt + gt: keep stricter (larger) bound
                return $cmp > 0 ? $this->withPagination($this->source->gt($column, $value)) : $this;
            }
            if ($this->operator === Operator::Gte) {
                // gte + gt: gt is stricter when value >= existing
                return $cmp >= 0 ? $this->withPagination($this->source->gt($column, $value)) : $this;
            }
            if ($this->operator === Operator::Lt || $this->operator === Operator::Lte) {
                if ($cmp >= 0) {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::gt($column, $value);
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) >= 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Gt || $this->operator === Operator::Gte) {
                return $cmp > 0 ? $this->withPagination($this->source->gte($column, $value)) : $this;
            }
            if ($this->operator === Operator::Lt) {
                if ($cmp >= 0) {
                    return EmptyTable::from($this);
                }
            }
            if ($this->operator === Operator::Lte) {
                if ($cmp < 0) {
                    // valid range, fall through
                } elseif ($cmp === 0) {
                    return $this->withPagination($this->source->eq($column, $value));
                } else {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::gte($column, $value);
    }

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }
}
