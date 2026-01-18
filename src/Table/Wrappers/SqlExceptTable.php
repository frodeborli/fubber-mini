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
 * SQL EXCEPT - rows from left that don't exist in right
 *
 * Uses positional column matching (SQL standard for set operations).
 * Predicates push down to left side only (we're filtering left, right is exclusion set).
 *
 * ```php
 * // SELECT * FROM a EXCEPT SELECT * FROM b
 * new SqlExceptTable($tableA, $tableB)
 * ```
 */
class SqlExceptTable extends AbstractTable
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
                'EXCEPT requires same number of columns: ' . count($leftCols) . ' vs ' . count($rightCols)
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

        // Wrap right side with OptimizingTable if it has no useful index
        $probeTable = $this->right;
        if (!$this->hasUsefulIndex($this->right, $rightCols) && $probeTable instanceof AbstractTable) {
            $probeTable = OptimizingTable::from($probeTable)
                ->withExpectedHasCalls($this->left->count());
        }

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($this->left->columns(...$leftCols) as $id => $row) {
            // Build member with right's column names for has() check
            $member = $this->remapRow($row, $leftCols, $rightCols);

            // Exclude if row exists in right side
            if ($probeTable->has($member)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            // Output with our (left's) column names
            $out = $this->remapRow($row, $leftCols, $cols);
            yield $id => $out;
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    /**
     * Check if a table has useful indexes for membership testing
     */
    private function hasUsefulIndex(TableInterface $table, array $columns): bool
    {
        $tableCols = $table->getColumns();
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
    // Filter methods - push down to LEFT only (we're filtering left rows)
    // Right is the exclusion set and should remain unchanged
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->eq($column, $value);
        return $c;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->lt($column, $value);
        return $c;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->lte($column, $value);
        return $c;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->gt($column, $value);
        return $c;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->gte($column, $value);
        return $c;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->in($column, $values);
        return $c;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $c = clone $this;
        $c->left = $this->left->like($column, $pattern);
        return $c;
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
        // Must exist in left AND NOT in right
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

        return $this->left->has($member) && !$this->right->has($rightMember);
    }
}
