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
 * No join condition - pure cartesian product.
 *
 * ```php
 * // SELECT * FROM users CROSS JOIN products
 * new CrossJoinTable($users, $products)
 * ```
 */
class CrossJoinTable extends AbstractTable
{
    /** @var list<callable(object): bool> Join predicates evaluated during iteration */
    private array $joinPredicates = [];

    /** @var list<array{left: string, right: string}> Equi-join conditions for hash join optimization */
    private array $equiJoins = [];

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

        // Merge column definitions - preserve index info since columns map to single source
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
        // Use hash join if we have equi-join conditions (O(n+m) vs O(n*m))
        if (!empty($this->equiJoins)) {
            yield from $this->materializeWithHashJoin();
            return;
        }

        $rowId = 0;
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();
        $hasPredicates = !empty($this->joinPredicates);

        foreach ($this->left as $leftRow) {
            foreach ($this->right as $rightRow) {
                $merged = $this->mergeRows($leftRow, $rightRow);

                // Evaluate join predicates early - before offset/limit processing
                if ($hasPredicates) {
                    $passes = true;
                    foreach ($this->joinPredicates as $predicate) {
                        if (!$predicate($merged)) {
                            $passes = false;
                            break;
                        }
                    }
                    if (!$passes) {
                        continue;
                    }
                }

                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => $merged;

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
        $output = new \stdClass();
        foreach ($left as $col => $val) {
            $output->$col = $val;
        }
        foreach ($right as $col => $val) {
            $output->$col = $val;
        }
        return $output;
    }

    /**
     * Hash join implementation for equi-join conditions
     *
     * Builds hash table on right side, probes from left side.
     * O(n+m) instead of O(n*m) for nested loops.
     */
    private function materializeWithHashJoin(): Traversable
    {
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();
        $hasPredicates = !empty($this->joinPredicates);

        // Build hash table on right side keyed by join columns
        $hash = [];
        foreach ($this->right as $rightRow) {
            $key = $this->buildHashKey($rightRow);
            $hash[$key][] = $rightRow;
        }

        // Probe from left side
        foreach ($this->left as $leftRow) {
            $key = $this->buildHashKey($leftRow);

            // Only iterate matching right rows (or none if no match)
            foreach ($hash[$key] ?? [] as $rightRow) {
                $merged = $this->mergeRows($leftRow, $rightRow);

                // Apply additional predicates
                if ($hasPredicates) {
                    $passes = true;
                    foreach ($this->joinPredicates as $predicate) {
                        if (!$predicate($merged)) {
                            $passes = false;
                            break;
                        }
                    }
                    if (!$passes) {
                        continue;
                    }
                }

                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => $merged;

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Build hash key from row based on equi-join columns
     *
     * Checks which column from the equi-join exists on the row.
     */
    private function buildHashKey(object $row): string
    {
        $parts = [];
        foreach ($this->equiJoins as $join) {
            $leftCol = $join['left'];
            $rightCol = $join['right'];

            // Use whichever column exists on this row
            if (property_exists($row, $leftCol)) {
                $value = $row->$leftCol;
            } elseif (property_exists($row, $rightCol)) {
                $value = $row->$rightCol;
            } else {
                $value = null;
            }

            $parts[] = $value === null ? "\0NULL\0" : (string)$value;
        }
        return implode("\0|\0", $parts);
    }

    /**
     * Add a join predicate evaluated during iteration
     *
     * @param callable(object): bool $predicate Returns true if row passes
     */
    public function withJoinCondition(callable $predicate): self
    {
        $new = clone $this;
        $new->joinPredicates[] = $predicate;
        return $new;
    }

    /**
     * Add an equi-join condition for hash join optimization
     *
     * @param string $leftCol Column name from one side of the equality
     * @param string $rightCol Column name from the other side
     */
    public function withEquiJoin(string $leftCol, string $rightCol): self
    {
        $new = clone $this;
        $new->equiJoins[] = ['left' => $leftCol, 'right' => $rightCol];
        return $new;
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
        // Count requires iteration - no easy optimization for joins
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $count;
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
        $new->joinPredicates = $this->joinPredicates;
        $new->equiJoins = $this->equiJoins;
        if ($this->getLimit() !== null) {
            $new = $new->limit($this->getLimit());
        }
        if ($this->getOffset() > 0) {
            $new = $new->offset($this->getOffset());
        }
        return $new;
    }
}
