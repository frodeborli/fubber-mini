<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use mini\Table\Utility\PredicateFilter;
use Traversable;

// Filter wrapper imports
use function class_exists;

/**
 * Right join of two tables with ON condition
 *
 * Yields all right rows, with matching left rows merged.
 * Unmatched right rows have NULL for all left columns.
 * Uses property-based binding: left table must have '__bind__' property with Predicate.
 *
 * ```php
 * // SELECT * FROM users u RIGHT JOIN orders o ON u.id = o.user_id
 * new RightJoinTable(
 *     $users->withAlias('u')->withProperty('__bind__', p->eqBind('u.id', ':o.user_id')),
 *     $orders->withAlias('o')
 * )
 * ```
 */
class RightJoinTable extends AbstractTable
{
    private Predicate $bindPredicate;
    private array $bindParams;
    private array $leftColNames;

    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
    ) {
        // Extract bind predicate from left's property
        $bindPredicate = $left->getProperty('__bind__');
        if (!$bindPredicate instanceof Predicate) {
            throw new \InvalidArgumentException(
                'RIGHT JOIN requires __bind__ property with Predicate on left table'
            );
        }
        $this->bindPredicate = $bindPredicate;
        $this->bindParams = $bindPredicate->getUnboundParams();

        if (empty($this->bindParams)) {
            throw new \InvalidArgumentException(
                'RIGHT JOIN requires at least one bind parameter (e.g., eqBind)'
            );
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();
        $this->leftColNames = array_keys($leftCols);

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
                    "Column name conflict in RIGHT JOIN: '$name'. Use withAlias() to disambiguate."
                );
            }
        }

        // Merge column definitions - left columns first, then right
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

        // For each right row, find matching left rows
        foreach ($this->right as $rightRow) {
            // Build bindings from right row
            $bindings = $this->extractBindings($rightRow);

            // Apply bound predicate as filters to left table
            $boundPredicate = $this->bindPredicate->bind($bindings);
            $matchingLeft = PredicateFilter::apply($this->left, $boundPredicate);

            $hadMatch = false;
            foreach ($matchingLeft as $leftRow) {
                $hadMatch = true;

                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => $this->mergeRows($leftRow, $rightRow);

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }

            // No match: emit right row with NULLs for left columns
            if (!$hadMatch) {
                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => $this->mergeRowWithNulls($rightRow);

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Extract binding values from a right row
     */
    private function extractBindings(object $row): array
    {
        $bindings = [];
        foreach ($this->bindParams as $param) {
            $colName = ltrim($param, ':');
            $bindings[$param] = $row->$colName ?? null;
        }
        return $bindings;
    }

    /**
     * Merge two rows into a single object (left columns first)
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
     * Merge right row with NULL values for left columns
     */
    private function mergeRowWithNulls(object $right): object
    {
        $output = new \stdClass();
        // Left columns first (as NULLs)
        foreach ($this->leftColNames as $col) {
            $output->$col = null;
        }
        // Then right columns
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
    // Filter methods - wrap in FilteredTable since pushdown is complex for RIGHT JOIN
    // ─────────────────────────────────────────────────────────────────────────

    public function eq(string $column, mixed $value): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->eq($column, $value));
    }

    public function lt(string $column, mixed $value): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->lt($column, $value));
    }

    public function lte(string $column, mixed $value): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->lte($column, $value));
    }

    public function gt(string $column, mixed $value): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->gt($column, $value));
    }

    public function gte(string $column, mixed $value): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->gte($column, $value));
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->in($column, $values));
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return new FilteredTable($this, (new Predicate())->like($column, $pattern));
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $count;
    }
}
