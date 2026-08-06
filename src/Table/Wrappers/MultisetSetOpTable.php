<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Types\Operator;
use Traversable;

/**
 * SQL `INTERSECT ALL` / `EXCEPT ALL` — multiset (bag) set operations.
 *
 * The `DISTINCT` forms of INTERSECT/EXCEPT are membership tests, so
 * {@see SqlIntersectTable} and {@see SqlExceptTable} implement them as
 * semi-/anti-joins. The `ALL` forms are *not* membership tests: SQL:2003
 * defines them on multiplicities. For a row value R occurring `m` times on
 * the left and `n` times on the right:
 *
 * - `INTERSECT ALL` emits R `min(m, n)` times
 * - `EXCEPT ALL` emits R `max(m - n, 0)` times
 *
 * Reusing the semi-/anti-join wrappers for the ALL forms answered a different
 * question (all m, or none) and produced silently wrong row counts.
 *
 * Column names and count come from the left side, matched positionally, as
 * the standard requires. The right side is counted eagerly — that is inherent
 * to multiset semantics — while the left side streams.
 */
class MultisetSetOpTable extends AbstractTable
{
    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
        private bool $intersect,
    ) {
        // Freeze sides carrying their own pagination so filter pushdown
        // cannot escape their result sets.
        if ($left instanceof AbstractTable && ($left->getLimit() !== null || $left->getOffset() > 0)) {
            $this->left = $left = BarrierTable::from($left);
        }
        if ($right instanceof AbstractTable && ($right->getLimit() !== null || $right->getOffset() > 0)) {
            $this->right = $right = BarrierTable::from($right);
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();

        if (count($leftCols) !== count($rightCols)) {
            $op = $intersect ? 'INTERSECT ALL' : 'EXCEPT ALL';
            throw new \InvalidArgumentException(
                $op . ' requires same number of columns: ' . count($leftCols) . ' vs ' . count($rightCols)
            );
        }

        parent::__construct(...array_values($leftCols));
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $cols = array_unique([...array_keys($this->getColumns()), ...$additionalColumns]);
        $leftCols = array_keys($this->left->getColumns());
        $rightCols = array_keys($this->right->getColumns());

        $limit = $this->getLimit();
        $offset = $this->getOffset();

        if ($limit !== null && $limit <= 0) {
            return;
        }

        // Multiplicity of every distinct row value on the right side.
        $rightCounts = [];
        foreach ($this->right->columns(...$rightCols) as $row) {
            $key = self::rowKey($row, $rightCols);
            $rightCounts[$key] = ($rightCounts[$key] ?? 0) + 1;
        }

        $skipped = 0;
        $emitted = 0;

        foreach ($this->left->columns(...$leftCols) as $id => $row) {
            $key = self::rowKey($row, $leftCols);
            $available = $rightCounts[$key] ?? 0;

            if ($this->intersect) {
                // min(m, n): consume one right occurrence per emitted row.
                if ($available === 0) {
                    continue;
                }
                $rightCounts[$key] = $available - 1;
            } else {
                // max(m - n, 0): the first n left occurrences are cancelled.
                if ($available > 0) {
                    $rightCounts[$key] = $available - 1;
                    continue;
                }
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            yield $id => self::remapRow($row, $leftCols, $cols);
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    /**
     * Canonical key for a row value, positionally over $cols.
     *
     * NULL is a distinct value here: SQL set operations treat NULLs as
     * duplicates of each other (unlike `=`), so they must group together
     * without colliding with the string "".
     */
    private static function rowKey(object $row, array $cols): string
    {
        $parts = [];
        foreach ($cols as $col) {
            $val = $row->$col ?? null;
            $parts[] = $val === null ? "\x00" : "\x01" . $val;
        }
        return implode("\x00", $parts);
    }

    /**
     * Remap row from source columns to target columns (positional)
     */
    private static function remapRow(object $row, array $sourceCols, array $targetCols): object
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
    // Filters. Multiplicities are only well defined over the full operand
    // result sets, so filters are applied on top of the result rather than
    // pushed into the sides.
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
