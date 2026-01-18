<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use Traversable;

/**
 * Cross join (Cartesian product) of two tables
 *
 * Yields every combination of rows from both tables.
 * Pure cartesian product - no join conditions.
 *
 * For joins with conditions, VirtualDatabase should use InnerJoinTable instead.
 *
 * ```php
 * // SELECT * FROM users CROSS JOIN products
 * new CrossJoinTable($users, $products)
 * ```
 */
class CrossJoinTable extends AbstractTable
{
    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
    ) {
        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();

        // Validate no column name conflicts
        foreach ($leftCols as $name => $_) {
            if (isset($rightCols[$name])) {
                throw new \InvalidArgumentException(
                    "Column name conflict in CROSS JOIN: '$name'. Use withAlias() to disambiguate."
                );
            }
        }

        // Merge column definitions
        $merged = [];
        foreach ($leftCols as $name => $def) {
            $merged[] = new ColumnDef($name, $def->type, $def->index);
        }
        foreach ($rightCols as $name => $def) {
            $merged[] = new ColumnDef($name, $def->type, $def->index);
        }

        parent::__construct(...$merged);
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($this->left as $leftRow) {
            foreach ($this->right as $rightRow) {
                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => $this->mergeRows($leftRow, $rightRow);

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Merge two rows into a single object
     */
    private function mergeRows(object $left, object $right): object
    {
        return (object) ((array) $left + (array) $right);
    }

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Filter pushdown: apply filters to the appropriate source table
    // ─────────────────────────────────────────────────────────────────────────

    public function eq(string $column, mixed $value): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $value);
    }

    public function lt(string $column, mixed $value): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $value);
    }

    public function lte(string $column, mixed $value): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $value);
    }

    public function gt(string $column, mixed $value): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $value);
    }

    public function gte(string $column, mixed $value): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $value);
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $values);
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return $this->pushFilter(__FUNCTION__, $column, $pattern);
    }

    public function count(): int
    {
        return iterator_count($this);
    }

    /**
     * Push a filter operation to the appropriate source table
     */
    private function pushFilter(string $method, string $column, mixed $value): TableInterface
    {
        $leftCols = $this->left->getColumns();
        $rightCols = $this->right->getColumns();

        // Try exact match first
        if (isset($leftCols[$column])) {
            $filtered = $this->left->$method($column, $value);
            return $this->withFilteredSources($filtered, $this->right);
        }

        if (isset($rightCols[$column])) {
            $filtered = $this->right->$method($column, $value);
            return $this->withFilteredSources($this->left, $filtered);
        }

        // Try unqualified column match (e.g., 'a3' matches 't3.a3')
        if (!str_contains($column, '.')) {
            foreach ($leftCols as $name => $_) {
                if (str_ends_with($name, '.' . $column)) {
                    $filtered = $this->left->$method($name, $value);
                    return $this->withFilteredSources($filtered, $this->right);
                }
            }
            foreach ($rightCols as $name => $_) {
                if (str_ends_with($name, '.' . $column)) {
                    $filtered = $this->right->$method($name, $value);
                    return $this->withFilteredSources($this->left, $filtered);
                }
            }
        }

        throw new \InvalidArgumentException("Unknown column in CROSS JOIN: '$column'");
    }

    /**
     * Create new join with filtered source tables, preserving limit/offset
     */
    private function withFilteredSources(TableInterface $left, TableInterface $right): TableInterface
    {
        $new = new self($left, $right);
        if ($this->getLimit() !== null) {
            $new = $new->limit($this->getLimit());
        }
        if ($this->getOffset() > 0) {
            $new = $new->offset($this->getOffset());
        }
        return $new;
    }
}
