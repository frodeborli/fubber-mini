<?php

namespace mini\Table;

use Traversable;

/**
 * Concatenation of two tables (UNION ALL semantics)
 *
 * Yields all rows from both tables without deduplication.
 * For SQL UNION (with deduplication), wrap in DistinctTable.
 *
 * ```php
 * // UNION ALL - all rows from both
 * new ConcatTable($tableA, $tableB);
 *
 * // UNION - deduplicated
 * new DistinctTable(new ConcatTable($tableA, $tableB));
 * ```
 */
class ConcatTable extends AbstractTable
{
    public function __construct(
        private TableInterface $a,
        private TableInterface $b,
    ) {
        // Freeze sides with pagination to prevent filter pushdown from escaping their result sets
        if ($a instanceof AbstractTable && ($a->getLimit() !== null || $a->getOffset() > 0)) {
            $this->a = $a = BarrierTable::from($a);
        }
        if ($b instanceof AbstractTable && ($b->getLimit() !== null || $b->getOffset() > 0)) {
            $this->b = $b = BarrierTable::from($b);
        }

        $aCols = $a->getColumns();
        $bCols = $b->getColumns();

        // Validate matching column count (SQL UNION requirement)
        if (count($aCols) !== count($bCols)) {
            throw new \InvalidArgumentException(
                'UNION requires same number of columns: ' . count($aCols) . ' vs ' . count($bCols)
            );
        }

        // Use first table's column names (SQL UNION standard)
        // We don't require column name match, only count match
        parent::__construct(...array_values($aCols));
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $cols = array_unique([...array_keys($this->getColumns()), ...$additionalColumns]);
        $aCols = array_keys($this->a->getColumns());
        $bCols = array_keys($this->b->getColumns());

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // Yield from first table
        foreach ($this->a->columns(...$aCols) as $row) {
            if ($skipped++ < $offset) {
                continue;
            }

            // Remap to our column names if different
            $out = $this->remapRow($row, $aCols, $cols);
            yield $out;

            if ($limit !== null && ++$emitted >= $limit) {
                return;
            }
        }

        // Yield from second table
        foreach ($this->b->columns(...$bCols) as $row) {
            if ($skipped++ < $offset) {
                continue;
            }

            // Remap to our column names if different
            $out = $this->remapRow($row, $bCols, $cols);
            yield $out;

            if ($limit !== null && ++$emitted >= $limit) {
                return;
            }
        }
    }

    /**
     * Remap row from source columns to target columns
     */
    private function remapRow(object $row, array $sourceCols, array $targetCols): object
    {
        $out = new \stdClass();
        foreach ($targetCols as $i => $targetCol) {
            $sourceCol = $sourceCols[$i] ?? $targetCol;
            $out->$targetCol = $row->$sourceCol ?? null;
        }
        return $out;
    }

    public function count(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }
        return $this->cachedCount = iterator_count($this);
    }

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }

    // -------------------------------------------------------------------------
    // Filter methods - wrap in FilteredTable (filters apply to concatenated result)
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Eq, $value);
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Lt, $value);
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Lte, $value);
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Gt, $value);
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Gte, $value);
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return new FilteredTable($this, $column, Operator::In, $values);
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Like, $pattern);
    }
}
