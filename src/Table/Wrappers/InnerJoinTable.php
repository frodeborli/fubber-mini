<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use mini\Table\Types\IndexType;
use mini\Table\Types\Operator;
use Traversable;

/**
 * Inner join of two tables with equi-join condition
 *
 * Yields rows where the join condition matches between left and right tables.
 * Uses property-based binding: left table must have '__bind__' property with Predicate.
 *
 * Basic nested loop: iterate right, probe left with eq() for each row.
 */
class InnerJoinTable extends AbstractTable
{
    private Predicate $bindPredicate;

    /** @var string[] Unbound parameters of the bind predicate */
    private array $bindParams;

    /**
     * True when the join is a single `left.col = right.col` condition, which
     * the sort-merge and hash strategies can execute. Anything else (a
     * non-equality comparison, or several conditions) falls back to a nested
     * loop driven by the predicate itself — those strategies key on equality
     * and would silently return equi-join rows for `ON a.x > b.y`.
     */
    private bool $equiJoin;

    /** @var string Left column name for join (equi joins only) */
    private string $leftCol;

    /** @var string Right column name for join (equi joins only) */
    private string $rightCol;

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

        $conditions = $bindPredicate->getConditions();
        if ($conditions === []) {
            throw new \InvalidArgumentException(
                'INNER JOIN requires at least one join condition'
            );
        }

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();

        $this->equiJoin = count($conditions) === 1
            && $conditions[0]['operator'] === Operator::Eq;

        if ($this->equiJoin) {
            $cond = $conditions[0];
            $this->leftCol = $cond['column'];
            $this->rightCol = ltrim($cond['value'], ':');

            // Validate columns exist
            if (!isset($leftCols[$this->leftCol])) {
                throw new \InvalidArgumentException(
                    "Left join column '{$this->leftCol}' does not exist"
                );
            }
            if (!isset($rightCols[$this->rightCol])) {
                throw new \InvalidArgumentException(
                    "Right join column '{$this->rightCol}' does not exist"
                );
            }
        } else {
            if ($this->bindParams === []) {
                throw new \InvalidArgumentException(
                    'INNER JOIN requires at least one bind parameter (e.g., eqBind)'
                );
            }
            foreach ($this->bindParams as $param) {
                $colName = ltrim($param, ':');
                if (!isset($rightCols[$colName])) {
                    throw new \InvalidArgumentException(
                        "Bind parameter '$param' references unknown right column: $colName"
                    );
                }
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

        // Merge column definitions
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
        if (!$this->equiJoin) {
            yield from $this->nestedLoopJoin();
            return;
        }

        $leftCol = $this->leftCol;
        $rightCol = $this->rightCol;

        // Check index status on join columns
        $leftCols = $this->left->getColumns();
        $rightCols = $this->right->getColumns();
        $leftIndexed = $leftCols[$leftCol]->index->isIndexed();
        $rightIndexed = $rightCols[$rightCol]->index->isIndexed();

        if ($leftIndexed || $rightIndexed) {
            // Sort-merge join: at least one side can sort efficiently
            yield from $this->sortMergeJoin();
        } else {
            // Neither side indexed: use partitioned hash join
            yield from $this->blockHashJoin();
        }
    }

    /**
     * Nested loop join driven by the bind predicate
     *
     * Handles join conditions the equality strategies cannot express:
     * `ON a.x > b.y`, `ON a.x = b.y AND a.z = b.w`, and so on.
     */
    private function nestedLoopJoin(): Traversable
    {
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // LIMIT 0 must emit nothing. The loops below use an emit-then-test
        // pattern, which would otherwise always yield one row before the
        // limit is first consulted.
        if ($limit !== null && $limit <= 0) {
            return;
        }
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;

        foreach ($this->left as $leftRow) {
            foreach ($this->right as $rightRow) {
                $bindings = [];
                $hasNull = false;
                foreach ($this->bindParams as $param) {
                    $colName = ltrim($param, ':');
                    $value = $rightRow->$colName ?? null;
                    if ($value === null) {
                        // A comparison with NULL is UNKNOWN, never a match.
                        $hasNull = true;
                        break;
                    }
                    $bindings[$param] = $value;
                }
                if ($hasNull) {
                    continue;
                }

                if (!$this->bindPredicate->bind($bindings)->test($leftRow)) {
                    continue;
                }

                if ($skipped++ < $offset) {
                    continue;
                }

                yield $rowId++ => (object) ((array) $leftRow + (array) $rightRow);

                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * Sort-merge join: sort both sides and merge matching runs
     */
    private function sortMergeJoin(): Traversable
    {
        $leftCol = $this->leftCol;
        $rightCol = $this->rightCol;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // LIMIT 0 must emit nothing. The loops below use an emit-then-test
        // pattern, which would otherwise always yield one row before the
        // limit is first consulted.
        if ($limit !== null && $limit <= 0) {
            return;
        }

        // Get sorted iterators - use order() to let each table sort efficiently
        $sortedLeft = $this->left->order($leftCol);
        $sortedRight = $this->right->order($rightCol);

        $leftIter = $sortedLeft->getIterator();
        $rightIter = $sortedRight->getIterator();

        $leftIter->rewind();
        $rightIter->rewind();

        $rowId = 0;
        $skipped = 0;
        $emitted = 0;

        // Buffer for handling duplicate keys. Collecting a left-run advances
        // $leftIter past the run, so right rows are matched against the
        // buffered run key ($currentLeftKey), not $leftIter->current().
        $leftBuffer = [];
        $currentLeftKey = null;
        $haveBuffer = false;

        while ($rightIter->valid()) {
            $rightRow = $rightIter->current();
            $rightKey = $rightRow->$rightCol;

            // NULL = NULL is UNKNOWN, not true: a NULL key never joins.
            if ($rightKey === null) {
                $rightIter->next();
                continue;
            }

            // Current right row matches the buffered left run
            // Loose comparison, to stay consistent with the `<`/`>` tests
            // that advance the two cursors below: with strict `===` an int 1
            // and a float 1.0 were ordered equal but never matched, so an
            // int column joined to a float column silently produced no rows.
            if ($haveBuffer && $currentLeftKey == $rightKey) {
                foreach ($leftBuffer as $lr) {
                    if ($skipped++ < $offset) {
                        continue;
                    }
                    yield $rowId++ => (object) ((array) $lr + (array) $rightRow);
                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }
                $rightIter->next();
                continue;
            }

            if (!$leftIter->valid()) {
                // Left exhausted and buffer doesn't match: no more matches
                // possible since both sides are ascending.
                return;
            }

            $leftKey = $leftIter->current()->$leftCol;

            // NULL sorts first; skip it, it can never match.
            if ($leftKey === null) {
                $leftIter->next();
                $leftBuffer = [];
                $currentLeftKey = null;
                $haveBuffer = false;
                continue;
            }

            if ($leftKey < $rightKey) {
                // Left is behind, advance it
                $leftIter->next();
                $leftBuffer = [];
                $currentLeftKey = null;
                $haveBuffer = false;
            } elseif ($leftKey > $rightKey) {
                // Right is behind, advance it
                $rightIter->next();
            } else {
                // Keys match - collect all left rows with this key; the
                // buffered-run branch above emits the combinations.
                $leftBuffer = [];
                $currentLeftKey = $leftKey;
                $haveBuffer = true;
                while ($leftIter->valid()) {
                    $lr = $leftIter->current();
                    if ($lr->$leftCol != $leftKey) {
                        break;
                    }
                    $leftBuffer[] = $lr;
                    $leftIter->next();
                }
            }
        }
    }

    /**
     * Block nested loop join with hash probe
     *
     * Processes left side in chunks, scanning right side once per chunk.
     * Memory bounded to chunk size, trades memory for right-side scans.
     */
    private function blockHashJoin(): Traversable
    {
        $leftCol = $this->leftCol;
        $rightCol = $this->rightCol;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // LIMIT 0 must emit nothing. The loops below use an emit-then-test
        // pattern, which would otherwise always yield one row before the
        // limit is first consulted.
        if ($limit !== null && $limit <= 0) {
            return;
        }

        // TODO: Tune chunk size - can probably be 1000 or so
        $chunkSize = 64;
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;

        // Process left side in chunks
        $hashTable = [];
        $chunkCount = 0;
        $leftIter = $this->left->getIterator();

        foreach ($leftIter as $leftRow) {
            $key = $leftRow->$leftCol;
            // NULL = NULL is UNKNOWN, not true: a NULL key never joins.
            // (It would also collapse onto the '' array key.)
            if ($key === null) {
                continue;
            }
            $hashTable[self::hashKey($key)][] = $leftRow;
            $chunkCount++;

            // When chunk is full, scan right side
            if ($chunkCount >= $chunkSize) {
                // Full scan of right, probe hash table
                foreach ($this->right as $rightRow) {
                    $key = $rightRow->$rightCol;
                    if ($key === null) {
                        continue;
                    }
                    $key = self::hashKey($key);
                    if (!isset($hashTable[$key])) {
                        continue;
                    }

                    foreach ($hashTable[$key] as $matchedLeft) {
                        if ($skipped++ < $offset) {
                            continue;
                        }
                        yield $rowId++ => (object) ((array) $matchedLeft + (array) $rightRow);
                        if ($limit !== null && ++$emitted >= $limit) {
                            return;
                        }
                    }
                }

                // Clear chunk for next batch
                $hashTable = [];
                $chunkCount = 0;
            }
        }

        // Process remaining rows in final partial chunk
        if ($chunkCount > 0) {
            foreach ($this->right as $rightRow) {
                $key = $rightRow->$rightCol;
                if ($key === null) {
                    continue;
                }
                $key = self::hashKey($key);
                if (!isset($hashTable[$key])) {
                    continue;
                }

                foreach ($hashTable[$key] as $matchedLeft) {
                    if ($skipped++ < $offset) {
                        continue;
                    }
                    yield $rowId++ => (object) ((array) $matchedLeft + (array) $rightRow);
                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }
            }
        }
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
    // Filter pushdown
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
     * Create new join with filtered source tables
     */
    private function withFilteredSources(TableInterface $left, TableInterface $right): TableInterface
    {
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

    /**
     * Array-key-safe join key.
     *
     * PHP truncates float array keys to int, so a raw hash table matched
     * 1.5 against 1.9 (both key 1) and emitted "not representable as an int"
     * warnings for very large floats. Integral floats keep the integer key so
     * 1 and 1.0 still join, as SQL requires.
     */
    private static function hashKey(mixed $value): int|string
    {
        if (!is_float($value)) {
            return $value;
        }
        if ($value >= (float) PHP_INT_MIN && $value <= (float) PHP_INT_MAX && (float) (int) $value === $value) {
            return (int) $value;
        }
        return (string) $value;
    }
}
