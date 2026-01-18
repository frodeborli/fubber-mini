<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Types\IndexType;
use mini\Table\Types\Operator;
use Traversable;

/**
 * SQL INTERSECT - rows from left that also exist in right
 *
 * Uses positional column matching (SQL standard for set operations).
 * Predicates push down to both sides since a row must exist in both.
 *
 * ```php
 * // SELECT * FROM a INTERSECT SELECT * FROM b
 * new SqlIntersectTable($tableA, $tableB)
 * ```
 */
class SqlIntersectTable extends AbstractTable
{
    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
    ) {
        // Freeze sides with pagination to prevent filter pushdown from escaping their result sets
        if ($left instanceof AbstractTable && ($left->getLimit() !== null || $left->getOffset() > 0)) {
            $this->left = $left = BarrierTable::from($left);
        }
        if ($right instanceof AbstractTable && ($right->getLimit() !== null || $right->getOffset() > 0)) {
            $this->right = $right = BarrierTable::from($right);
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();

        // Validate matching column count (SQL set operation requirement)
        if (count($leftCols) !== count($rightCols)) {
            throw new \InvalidArgumentException(
                'INTERSECT requires same number of columns: ' . count($leftCols) . ' vs ' . count($rightCols)
            );
        }

        // Use left table's column names (SQL standard)
        parent::__construct(...array_values($leftCols));
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $cols = array_unique([...array_keys($this->getColumns()), ...$additionalColumns]);
        $leftCols = array_keys($this->left->getColumns());
        $rightCols = array_keys($this->right->getColumns());

        // Query planning: iterate unindexed side, probe indexed side
        // This maximizes index usage for has() checks
        $leftHasIndex = $this->hasUsefulIndex($this->left, $leftCols);
        $rightHasIndex = $this->hasUsefulIndex($this->right, $rightCols);

        // Swap if left has indexes but right doesn't
        // (iterate right, probe left for better index utilization)
        $swapped = $leftHasIndex && !$rightHasIndex;

        if ($swapped) {
            $iterTable = $this->right;
            $probeTable = $this->left;
            $iterCols = $rightCols;
            $probeCols = $leftCols;
        } else {
            $iterTable = $this->left;
            $probeTable = $this->right;
            $iterCols = $leftCols;
            $probeCols = $rightCols;
        }

        // When neither side has indexes, wrap probe side with OptimizingTable
        // to adaptively build indexes based on actual performance
        if (!$leftHasIndex && !$rightHasIndex && $probeTable instanceof AbstractTable) {
            $probeTable = OptimizingTable::from($probeTable)
                ->withExpectedHasCalls($iterTable->count());
        }

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($iterTable->columns(...$iterCols) as $id => $row) {
            // Build member with probe table's column names for has() check
            $member = $this->remapRow($row, $iterCols, $probeCols);

            // Check if row exists in probe side
            if (!$probeTable->has($member)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            // Output with left's column names (SQL standard)
            $out = $this->remapRow($row, $iterCols, $cols);
            yield $id => $out;
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    /**
     * Check if a table has useful indexes for membership testing
     *
     * For has() to be efficient, the table needs an index on at least one
     * of its columns. AbstractTable.has() uses eq() + exists() on the first
     * unique/primary column it finds, or falls back to iteration.
     *
     * @param TableInterface $table Table to check
     * @param array $columns Column names that will be used for has() check
     */
    private function hasUsefulIndex(TableInterface $table, array $columns): bool
    {
        $tableCols = $table->getColumns();

        // Check if any of the has() columns has an index
        foreach ($columns as $col) {
            if (isset($tableCols[$col]) && $tableCols[$col]->index !== IndexType::None) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remap row from source columns to target columns (positional)
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

    // -------------------------------------------------------------------------
    // Filter methods - push down to BOTH sides (row must match in both)
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->eq($column, $value),
            $this->right->eq($rightCol, $value)
        );
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->lt($column, $value),
            $this->right->lt($rightCol, $value)
        );
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->lte($column, $value),
            $this->right->lte($rightCol, $value)
        );
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->gt($column, $value),
            $this->right->gt($rightCol, $value)
        );
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->gte($column, $value),
            $this->right->gte($rightCol, $value)
        );
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->in($column, $values),
            $this->right->in($rightCol, $values)
        );
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $rightCol = $this->mapColumnToRight($column);
        return new self(
            $this->left->like($column, $pattern),
            $this->right->like($rightCol, $pattern)
        );
    }

    /**
     * Map a column name from left to corresponding right column (positional)
     */
    private function mapColumnToRight(string $column): string
    {
        $leftCols = array_keys($this->left->getColumns());
        $rightCols = array_keys($this->right->getColumns());

        $index = array_search($column, $leftCols, true);
        if ($index === false) {
            throw new \InvalidArgumentException("Column '$column' not found in left table");
        }

        return $rightCols[$index];
    }

    // -------------------------------------------------------------------------
    // Other methods
    // -------------------------------------------------------------------------

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

    public function has(object $member): bool
    {
        // Must exist in left AND right
        $leftCols = array_keys($this->left->getColumns());
        $rightCols = array_keys($this->right->getColumns());

        // Remap member to right's column names
        $rightMember = new \stdClass();
        foreach ($rightCols as $i => $rightCol) {
            $leftCol = $leftCols[$i];
            if (property_exists($member, $leftCol)) {
                $rightMember->$rightCol = $member->$leftCol;
            }
        }

        return $this->left->has($member) && $this->right->has($rightMember);
    }
}
