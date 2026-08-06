<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Types\IndexType;
use mini\Table\Types\Operator;
use mini\Table\Predicate;
use Traversable;

/**
 * Left join of two tables with ON condition
 *
 * Yields all left rows, with matching right rows merged.
 * Unmatched left rows have NULL for all right columns.
 * Uses property-based binding: left table must have '__bind__' property with Predicate.
 *
 * ```php
 * // SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id
 * new LeftJoinTable(
 *     $users->withAlias('u')->withProperty('__bind__', p->eqBind('u.id', ':o.user_id')),
 *     $orders->withAlias('o')
 * )
 * ```
 */
class LeftJoinTable extends AbstractTable
{
    /** Maps the filter method name to the equivalent FilteredTable operator. */
    private const OPERATORS = [
        'eq' => Operator::Eq,
        'lt' => Operator::Lt,
        'lte' => Operator::Lte,
        'gt' => Operator::Gt,
        'gte' => Operator::Gte,
        'in' => Operator::In,
        'like' => Operator::Like,
    ];

    private Predicate $bindPredicate;
    private array $bindParams;
    private array $rightColNames;

    public function __construct(
        private TableInterface $left,
        private TableInterface $right,
    ) {
        // Extract bind predicate from left's property
        $bindPredicate = $left->getProperty('__bind__');
        if (!$bindPredicate instanceof Predicate) {
            throw new \InvalidArgumentException(
                'LEFT JOIN requires __bind__ property with Predicate on left table'
            );
        }
        $this->bindPredicate = $bindPredicate;
        $this->bindParams = $bindPredicate->getUnboundParams();

        if (empty($this->bindParams)) {
            throw new \InvalidArgumentException(
                'LEFT JOIN requires at least one bind parameter (e.g., eqBind)'
            );
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();
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
                    "Column name conflict in LEFT JOIN: '$name'. Use withAlias() to disambiguate."
                );
            }
        }

        // Merge column definitions - preserve index info since columns map to single source
        $merged = [];
        foreach ($leftCols as $name => $def) {
            $merged[] = new ColumnDef($name, $def->type, $def->index->isUnique() ? IndexType::Index : $def->index);
        }
        foreach ($rightCols as $name => $def) {
            $merged[] = new ColumnDef($name, $def->type, $def->index->isUnique() ? IndexType::Index : $def->index);
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

        // LIMIT 0 must emit nothing. The loops below use an emit-then-test
        // pattern, which would otherwise always yield one row before the
        // limit is first consulted.
        if ($limit !== null && $limit <= 0) {
            return;
        }

        foreach ($this->left as $leftRow) {
            $hadMatch = false;

            foreach ($this->right as $rightRow) {
                // Bind right values and test against left row
                $bindings = $this->extractBindings($rightRow);

                // A comparison with NULL is UNKNOWN, never a match — NULL keys
                // do not join, they fall through to the null-extended row.
                if (in_array(null, $bindings, true)) {
                    continue;
                }

                $boundPredicate = $this->bindPredicate->bind($bindings);

                if ($boundPredicate->test($leftRow)) {
                    $hadMatch = true;

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

                yield $rowId++ => $this->mergeRowWithNulls($leftRow);

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
    private function mergeRowWithNulls(object $left): object
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
     *
     * Only filters on LEFT columns may be pushed down: every left row survives
     * the join (null-extended when unmatched), so filtering before the join is
     * equivalent to filtering after it.
     *
     * Filters on RIGHT columns must NOT be pushed. The right side is the
     * null-supplying side: removing right rows before the join turns matched
     * rows into null-extended rows instead of removing them, which silently
     * returns extra rows. Those filters are applied to the join output.
     */
    private function pushFilter(string $method, string $column, mixed $value): TableInterface
    {
        $leftCols = $this->left->getColumns();

        if (isset($leftCols[$column])) {
            $filtered = $this->left->$method($column, $value);
            return $this->withFilteredSources($filtered, $this->right);
        }

        $rightCols = $this->right->getColumns();

        if (isset($rightCols[$column])) {
            return new FilteredTable($this, $column, self::OPERATORS[$method], $value);
        }

        throw new \InvalidArgumentException("Unknown column in LEFT JOIN: '$column'");
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
