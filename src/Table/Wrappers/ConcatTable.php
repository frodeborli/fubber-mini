<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\Operator;
use mini\Table\OrderDef;
use mini\Table\Utility\TablePropertiesTrait;
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
        // Skip validation if either side has unknown columns (e.g., PartialQuery)
        if (!empty($aCols) && !empty($bCols) && count($aCols) !== count($bCols)) {
            throw new \InvalidArgumentException(
                'UNION requires same number of columns: ' . count($aCols) . ' vs ' . count($bCols)
            );
        }

        // Use first table's column names if known, otherwise use second's, otherwise empty
        // We don't require column name match, only count match
        $cols = !empty($aCols) ? $aCols : $bCols;
        parent::__construct(...array_values($cols));
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
        // If column info unavailable, iterate directly (SELECT * semantics)
        $aIterator = empty($aCols) ? $this->a : $this->a->columns(...$aCols);
        foreach ($aIterator as $row) {
            if ($skipped++ < $offset) {
                continue;
            }

            // Remap to our column names if different, or pass through if unknown
            $out = empty($cols) ? $row : $this->remapRow($row, $aCols, $cols);
            yield $out;

            if ($limit !== null && ++$emitted >= $limit) {
                return;
            }
        }

        // Yield from second table
        // If column info unavailable, iterate directly (SELECT * semantics)
        $bIterator = empty($bCols) ? $this->b : $this->b->columns(...$bCols);
        foreach ($bIterator as $row) {
            if ($skipped++ < $offset) {
                continue;
            }

            // Remap to our column names if different, or pass through if unknown
            $out = empty($cols) ? $row : $this->remapRow($row, $bCols, $cols);
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
        return iterator_count($this);
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
