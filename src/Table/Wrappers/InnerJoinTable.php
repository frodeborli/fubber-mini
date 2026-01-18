<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use mini\Table\Types\IndexType;
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
    /** @var string Left column name for join */
    private string $leftCol;

    /** @var string Right column name for join */
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

        // Extract the single equi-join condition
        $conditions = $bindPredicate->getConditions();
        if (count($conditions) !== 1) {
            throw new \InvalidArgumentException(
                'INNER JOIN currently only supports single equi-join condition'
            );
        }

        $cond = $conditions[0];
        $this->leftCol = $cond['column'];
        $this->rightCol = ltrim($cond['value'], ':');

        $leftCols = $left->getColumns();
        $rightCols = $right->getColumns();

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
            $merged[] = new ColumnDef($name, $def->type, $def->index);
        }
        foreach ($rightCols as $name => $def) {
            $merged[] = new ColumnDef($name, $def->type, $def->index);
        }

        parent::__construct(...$merged);
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
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
     * Sort-merge join: sort both sides and merge matching runs
     */
    private function sortMergeJoin(): Traversable
    {
        $leftCol = $this->leftCol;
        $rightCol = $this->rightCol;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

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

        // Buffer for handling duplicate keys
        $leftBuffer = [];
        $currentLeftKey = null;

        while ($leftIter->valid() && $rightIter->valid()) {
            $leftRow = $leftIter->current();
            $rightRow = $rightIter->current();
            $leftKey = $leftRow->$leftCol;
            $rightKey = $rightRow->$rightCol;

            if ($leftKey < $rightKey) {
                // Left is behind, advance it
                $leftIter->next();
                $leftBuffer = [];
                $currentLeftKey = null;
            } elseif ($leftKey > $rightKey) {
                // Right is behind, advance it
                $rightIter->next();
            } else {
                // Keys match - collect all left rows with this key
                if ($currentLeftKey !== $leftKey) {
                    $leftBuffer = [];
                    $currentLeftKey = $leftKey;
                    while ($leftIter->valid()) {
                        $lr = $leftIter->current();
                        if ($lr->$leftCol !== $leftKey) {
                            break;
                        }
                        $leftBuffer[] = $lr;
                        $leftIter->next();
                    }
                }

                // Emit all combinations with current right row
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
            $hashTable[$key][] = $leftRow;
            $chunkCount++;

            // When chunk is full, scan right side
            if ($chunkCount >= $chunkSize) {
                // Full scan of right, probe hash table
                foreach ($this->right as $rightRow) {
                    $key = $rightRow->$rightCol;
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
        // Recreate the bind predicate
        $predicate = (new Predicate())->eqBind($this->leftCol, ':' . $this->rightCol);
        $leftWithBind = $left->withProperty('__bind__', $predicate);

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
