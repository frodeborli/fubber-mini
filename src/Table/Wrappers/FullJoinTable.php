<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use Traversable;

/**
 * Full outer join of two tables with ON condition
 *
 * Yields all rows from both tables:
 * - Matched rows have data from both tables
 * - Unmatched left rows have NULL for right columns
 * - Unmatched right rows have NULL for left columns
 *
 * Uses property-based binding: left table must have '__bind__' property with Predicate.
 *
 * ```php
 * // SELECT * FROM users u FULL JOIN orders o ON u.id = o.user_id
 * new FullJoinTable(
 *     $users->withAlias('u')->withProperty('__bind__', p->eqBind('u.id', ':o.user_id')),
 *     $orders->withAlias('o')
 * )
 * ```
 */
class FullJoinTable extends AbstractTable
{
    private Predicate $bindPredicate;
    private array $bindParams;
    private array $leftColNames;
    private array $rightColNames;

    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
    ) {
        // Extract bind predicate from left's property
        $bindPredicate = $left->getProperty('__bind__');
        if (!$bindPredicate instanceof Predicate) {
            throw new \InvalidArgumentException(
                'FULL JOIN requires __bind__ property with Predicate on left table'
            );
        }
        $this->bindPredicate = $bindPredicate;
        $this->bindParams = $bindPredicate->getUnboundParams();

        if (empty($this->bindParams)) {
            throw new \InvalidArgumentException(
                'FULL JOIN requires at least one bind parameter (e.g., eqBind)'
            );
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();
        $this->leftColNames = array_keys($leftCols);
        $this->rightColNames = array_keys($rightCols);

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
                    "Column name conflict in FULL JOIN: '$name'. Use withAlias() to disambiguate."
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

        // Track which right rows have been matched
        $matchedRightIds = [];

        // First pass: iterate left rows, find matching right rows
        foreach ($this->left as $leftRow) {
            $hadMatch = false;

            foreach ($this->right as $rightId => $rightRow) {
                // Bind right values and test against left row
                $bindings = $this->extractBindings($rightRow);
                $boundPredicate = $this->bindPredicate->bind($bindings);

                if ($boundPredicate->test($leftRow)) {
                    $hadMatch = true;
                    $matchedRightIds[$rightId] = true;

                    if ($skipped++ < $offset) {
                        continue;
                    }

                    yield $rowId++ => $this->mergeRows($leftRow, $rightRow);

                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }
            }

            // No match: emit left row with NULLs for right columns
            if (!$hadMatch) {
                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => $this->mergeLeftWithNulls($leftRow);

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }
        }

        // Second pass: emit unmatched right rows with NULL left columns
        foreach ($this->right as $rightId => $rightRow) {
            if (isset($matchedRightIds[$rightId])) {
                continue; // Already matched
            }

            if ($skipped++ < $offset) {
                continue;
            }

            yield $rowId++ => $this->mergeRightWithNulls($rightRow);

            if ($limit !== null && ++$emitted >= $limit) {
                return;
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
     * Merge left row with NULL values for right columns
     */
    private function mergeLeftWithNulls(object $left): object
    {
        $output = new \stdClass();
        foreach ($left as $col => $val) {
            $output->$col = $val;
        }
        foreach ($this->rightColNames as $col) {
            $output->$col = null;
        }
        return $output;
    }

    /**
     * Merge right row with NULL values for left columns
     */
    private function mergeRightWithNulls(object $right): object
    {
        $output = new \stdClass();
        foreach ($this->leftColNames as $col) {
            $output->$col = null;
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
    // Filter methods - wrap in FilteredTable since pushdown is complex for FULL JOIN
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
