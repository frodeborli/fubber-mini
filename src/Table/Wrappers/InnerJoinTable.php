<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use mini\Table\Types\IndexType;
use mini\Table\Utility\PredicateFilter;
use Traversable;

/**
 * Inner join of two tables with ON condition
 *
 * Yields rows where the join condition matches between left and right tables.
 * Uses property-based binding: left table must have '__bind__' property with Predicate.
 *
 * ```php
 * // SELECT * FROM users u INNER JOIN orders o ON u.id = o.user_id
 * new InnerJoinTable(
 *     $users->withAlias('u')->withProperty('__bind__', p->eqBind('u.id', ':o.user_id')),
 *     $orders->withAlias('o')
 * )
 * ```
 */
class InnerJoinTable extends AbstractTable
{
    private Predicate $bindPredicate;
    private array $bindParams;

    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
    ) {
        // Extract bind predicate from left's property
        $bindPredicate = $left->getProperty('__bind__');
        if (!$bindPredicate instanceof Predicate) {
            throw new \InvalidArgumentException(
                'INNER JOIN requires __bind__ property with Predicate on left table'
            );
        }
        $this->bindPredicate = $bindPredicate;
        $this->bindParams = $bindPredicate->getUnboundParams();

        if (empty($this->bindParams)) {
            throw new \InvalidArgumentException(
                'INNER JOIN requires at least one bind parameter (e.g., eqBind)'
            );
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();

        // Validate right has the referenced columns
        foreach ($this->bindParams as $param) {
            $colName = ltrim($param, ':');
            if (!isset($rightCols[$colName])) {
                throw new \InvalidArgumentException(
                    "Bind parameter '$param' references unknown right column: $colName"
                );
            }
        }

        // Validate no column name conflicts
        foreach ($leftCols as $name => $_) {
            if (isset($rightCols[$name])) {
                throw new \InvalidArgumentException(
                    "Column name conflict in INNER JOIN: '$name'. Use withAlias() to disambiguate."
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
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // Query planning: determine optimal iteration order based on indexes
        // Iterate the table WITHOUT indexes, probe the table WITH indexes
        $leftJoinCols = $this->getPredicateColumns();
        $rightJoinCols = array_map(fn($p) => ltrim($p, ':'), $this->bindParams);

        $leftHasIndex = $this->hasUsefulIndex($this->left, $leftJoinCols);
        $rightHasIndex = $this->hasUsefulIndex($this->right, $rightJoinCols);

        // Wrap probe side with OptimizingTable if neither side has indexes
        $probeLeft = $this->left;
        $probeRight = $this->right;
        if (!$leftHasIndex && !$rightHasIndex) {
            $probeLeft = $this->wrapWithOptimizing($probeLeft, $this->right->count(), $leftJoinCols);
            $probeRight = $this->wrapWithOptimizing($probeRight, $this->left->count(), $rightJoinCols);
        }

        // Swap if right has NO index but left DOES
        // (iterate right is wasteful if we can't probe left efficiently, but we CAN probe right)
        $shouldSwap = !$rightHasIndex && $leftHasIndex;

        if ($shouldSwap) {
            // Swapped: iterate left, probe right
            $invertedPredicate = $this->invertPredicate();
            $invertedParams = $invertedPredicate->getUnboundParams();

            foreach ($this->left as $leftRow) {
                $bindings = $this->extractBindingsFrom($leftRow, $invertedParams);
                $boundPredicate = $invertedPredicate->bind($bindings);
                $filteredRight = PredicateFilter::apply($probeRight, $boundPredicate);

                foreach ($filteredRight as $rightRow) {
                    if ($skipped++ < $offset) {
                        continue;
                    }

                    yield $rowId++ => $this->mergeRows($leftRow, $rightRow);

                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }
            }
        } else {
            // Normal: iterate right, probe left
            foreach ($this->right as $rightRow) {
                $bindings = $this->extractBindings($rightRow);
                $boundPredicate = $this->bindPredicate->bind($bindings);
                $filteredLeft = PredicateFilter::apply($probeLeft, $boundPredicate);

                foreach ($filteredLeft as $leftRow) {
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
    }

    /**
     * Check if table has useful index for given columns
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
     * Wrap table with OptimizingTable if possible
     *
     * For AliasTable, we need to unwrap, optimize the source, and re-wrap
     * since AliasTable delegates eq() to its source.
     */
    private function wrapWithOptimizing(TableInterface $table, int $expectedCalls, array $columns): TableInterface
    {
        // Handle AliasTable: unwrap, optimize source, re-wrap
        if ($table instanceof AliasTable) {
            $source = $table->getSource();
            if ($source instanceof AbstractTable) {
                // Map aliased column names back to original names for index hints
                $originalCols = [];
                foreach ($columns as $col) {
                    $originalCols[] = $table->resolveToOriginal($col);
                }

                $optimized = OptimizingTable::from($source)
                    ->withExpectedEqCalls($expectedCalls)
                    ->withIndexOn(...$originalCols);

                // Re-create AliasTable with optimized source
                return $table->withSource($optimized);
            }
            return $table;
        }

        // Direct wrapping for AbstractTable
        if ($table instanceof AbstractTable) {
            return OptimizingTable::from($table)
                ->withExpectedEqCalls($expectedCalls)
                ->withIndexOn(...$columns);
        }

        return $table;
    }

    /**
     * Get columns referenced by predicate (left side of join condition)
     */
    private function getPredicateColumns(): array
    {
        $cols = [];
        foreach ($this->bindPredicate->getConditions() as $cond) {
            if (!$cond['bound']) {
                $cols[] = $cond['column'];
            }
        }
        return $cols;
    }

    /**
     * Invert predicate for swapped join: swap column ↔ bind param
     *
     * Original: eqBind('u.id', ':o.user_id') - filter left where u.id = o.user_id
     * Inverted: eqBind('o.user_id', ':u.id') - filter right where o.user_id = u.id
     */
    private function invertPredicate(): Predicate
    {
        $inverted = new Predicate();

        foreach ($this->bindPredicate->getConditions() as $cond) {
            if (!$cond['bound']) {
                // Swap column and param
                $newColumn = ltrim($cond['value'], ':');  // ':o.user_id' → 'o.user_id'
                $newParam = ':' . $cond['column'];        // 'u.id' → ':u.id'

                $inverted = match ($cond['operator']) {
                    \mini\Table\Types\Operator::Eq => $inverted->eqBind($newColumn, $newParam),
                    \mini\Table\Types\Operator::Lt => $inverted->gtBind($newColumn, $newParam),  // < inverts to >
                    \mini\Table\Types\Operator::Lte => $inverted->gteBind($newColumn, $newParam),
                    \mini\Table\Types\Operator::Gt => $inverted->ltBind($newColumn, $newParam),
                    \mini\Table\Types\Operator::Gte => $inverted->lteBind($newColumn, $newParam),
                    default => throw new \RuntimeException("Cannot invert operator: " . $cond['operator']->name),
                };
            }
        }

        return $inverted;
    }

    /**
     * Extract binding values from a row using given params
     */
    private function extractBindingsFrom(object $row, array $params): array
    {
        $bindings = [];
        foreach ($params as $param) {
            $colName = ltrim($param, ':');
            $bindings[$param] = $row->$colName ?? null;
        }
        return $bindings;
    }

    /**
     * Extract binding values from a right row (original predicate)
     */
    private function extractBindings(object $row): array
    {
        return $this->extractBindingsFrom($row, $this->bindParams);
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

        if (isset($leftCols[$column])) {
            $filtered = $this->left->$method($column, $value);
            return $this->withFilteredSources($filtered, $this->right);
        }

        if (isset($rightCols[$column])) {
            $filtered = $this->right->$method($column, $value);
            return $this->withFilteredSources($this->left, $filtered);
        }

        throw new \InvalidArgumentException("Unknown column in INNER JOIN: '$column'");
    }

    /**
     * Create new join with filtered source tables, preserving bind and limit/offset
     */
    private function withFilteredSources(TableInterface $left, TableInterface $right): TableInterface
    {
        // Preserve the bind predicate on the left table
        $leftWithBind = $left->withProperty('__bind__', $this->bindPredicate);

        $new = new self($leftWithBind, $right);
        if ($this->getLimit() !== null) {
            $new = $new->limit($this->getLimit());
        }
        if ($this->getOffset() > 0) {
            $new = $new->offset($this->getOffset());
        }
        return $new;
    }
}
