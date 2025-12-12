<?php

namespace mini\Table;

use Traversable;

/**
 * Union of two tables (set union / OR operation)
 *
 * Yields rows from both tables, deduplicating by row ID.
 * Filter methods push down to both sides, allowing each to optimize independently.
 *
 * ```php
 * // WHERE status = 'active' OR status = 'pending'
 * $table->eq('status', 'active')->union($table->eq('status', 'pending'))
 * ```
 */
class UnionTable extends AbstractTable
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

        // Validate matching columns
        foreach ($aCols as $name => $_) {
            if (!isset($bCols[$name])) {
                throw new \InvalidArgumentException(
                    'UNION requires matching columns: column "' . $name . '" missing in second table'
                );
            }
        }
        foreach ($bCols as $name => $_) {
            if (!isset($aCols[$name])) {
                throw new \InvalidArgumentException(
                    'UNION requires matching columns: column "' . $name . '" missing in first table'
                );
            }
        }

        // Compute merged column definitions
        $merged = [];
        foreach ($aCols as $name => $defA) {
            $merged[] = $defA->commonWith($bCols[$name]);
        }

        parent::__construct(...$merged);
    }

    // -------------------------------------------------------------------------
    // Iteration and counting
    // -------------------------------------------------------------------------

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $cols = array_unique([...array_keys($this->getColumns()), ...$additionalColumns]);
        $a = $this->a->columns(...$cols);
        $b = $this->b->columns(...$cols);

        $seen = [];
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($a as $id => $row) {
            $seen[$id] = true;

            if ($skipped++ < $offset) {
                continue;
            }

            yield $id => $row;

            if (++$emitted === $limit) {
                return;
            }
        }

        foreach ($b as $id => $row) {
            if (isset($seen[$id]) || $skipped++ < $offset) {
                continue;
            }

            yield $id => $row;

            if (++$emitted === $limit) {
                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Filter methods - push down to both sides
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        return new self($this->a->eq($column, $value), $this->b->eq($column, $value));
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        return new self($this->a->lt($column, $value), $this->b->lt($column, $value));
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        return new self($this->a->lte($column, $value), $this->b->lte($column, $value));
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        return new self($this->a->gt($column, $value), $this->b->gt($column, $value));
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        return new self($this->a->gte($column, $value), $this->b->gte($column, $value));
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return new self($this->a->in($column, $values), $this->b->in($column, $values));
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return new self($this->a->like($column, $pattern), $this->b->like($column, $pattern));
    }

    // -------------------------------------------------------------------------
    // Membership and counting
    // -------------------------------------------------------------------------

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
}
