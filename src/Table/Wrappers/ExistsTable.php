<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use Traversable;

/**
 * Filters outer table rows based on existence in inner table
 *
 * Implements WHERE EXISTS / NOT EXISTS semantics with memory-bounded algorithms:
 * - Sort-merge when at least one side is indexed on correlation column
 * - Block hash when neither side is indexed (processes outer in chunks)
 *
 * ```php
 * // EXISTS (SELECT 1 FROM t2 WHERE t2.id = t1.id AND t2.cat = 'C5')
 * $filtered = $innerTable->eq('cat', 'C5');
 * $result = new ExistsTable($outerTable, $filtered, [['t1.id', 't2.id']], false);
 *
 * // NOT EXISTS
 * $result = new ExistsTable($outerTable, $filtered, [['t1.id', 't2.id']], true);
 * ```
 */
class ExistsTable extends AbstractTable
{
    /**
     * @param TableInterface $outer The outer table to filter
     * @param TableInterface $inner The inner table (subquery result, possibly pre-filtered)
     * @param array<array{0: string, 1: string}> $correlations Correlation pairs [[outerCol, innerCol], ...]
     * @param bool $negated True for NOT EXISTS
     */
    public function __construct(
        private TableInterface $outer,
        private TableInterface $inner,
        private array $correlations,
        private bool $negated = false,
    ) {
        if (empty($correlations)) {
            throw new \InvalidArgumentException('ExistsTable requires at least one correlation pair');
        }

        // Inherit column definitions from outer table
        $columns = [];
        foreach ($outer->getColumns() as $name => $def) {
            $columns[] = new \mini\Table\ColumnDef($name, $def->type, $def->index);
        }

        parent::__construct(...$columns);
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        // For single-column correlations, check if either side is indexed
        if (count($this->correlations) === 1) {
            $outerCol = $this->correlations[0][0];
            $innerCol = $this->correlations[0][1];

            $outerCols = $this->outer->getColumns();
            $innerCols = $this->inner->getColumns();

            $outerIndexed = isset($outerCols[$outerCol]) && $outerCols[$outerCol]->index->isIndexed();
            $innerIndexed = isset($innerCols[$innerCol]) && $innerCols[$innerCol]->index->isIndexed();

            if ($outerIndexed || $innerIndexed) {
                yield from $this->sortMergeExists();
                return;
            }
        }

        // Neither side indexed: use block hash approach
        yield from $this->blockHashExists();
    }

    /**
     * Sort-merge EXISTS: sort both sides and merge to find matches
     */
    private function sortMergeExists(): Traversable
    {
        $outerCol = $this->correlations[0][0];
        $innerCol = $this->correlations[0][1];
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // Get sorted iterators - use order() to let each table sort efficiently
        $sortedOuter = $this->outer->order($outerCol);
        $sortedInner = $this->inner->order($innerCol);

        $outerIter = $sortedOuter->getIterator();
        $innerIter = $sortedInner->getIterator();

        $outerIter->rewind();
        $innerIter->rewind();

        $rowId = 0;
        $skipped = 0;
        $emitted = 0;

        // For NOT EXISTS, we need to track which outer rows DON'T have matches
        if ($this->negated) {
            yield from $this->sortMergeNotExists($outerIter, $innerIter, $outerCol, $innerCol);
            return;
        }

        // EXISTS: yield outer rows that have at least one match in inner
        while ($outerIter->valid() && $innerIter->valid()) {
            $outerRow = $outerIter->current();
            $innerRow = $innerIter->current();
            $outerKey = $outerRow->$outerCol;
            $innerKey = $innerRow->$innerCol;

            if ($outerKey < $innerKey) {
                // No match for this outer row
                $outerIter->next();
            } elseif ($outerKey > $innerKey) {
                // Inner is behind, advance it
                $innerIter->next();
            } else {
                // Match found - yield outer row
                if ($skipped++ < $offset) {
                    // Skip duplicate outer rows with same key
                    $currentKey = $outerKey;
                    $outerIter->next();
                    while ($outerIter->valid() && $outerIter->current()->$outerCol === $currentKey) {
                        $outerIter->next();
                    }
                    continue;
                }

                yield $rowId++ => $outerRow;
                if ($limit !== null && ++$emitted >= $limit) {
                    return;
                }

                // Skip duplicate outer rows with same key (already matched)
                $currentKey = $outerKey;
                $outerIter->next();
                while ($outerIter->valid() && $outerIter->current()->$outerCol === $currentKey) {
                    $row = $outerIter->current();
                    if ($skipped++ < $offset) {
                        $outerIter->next();
                        continue;
                    }
                    yield $rowId++ => $row;
                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                    $outerIter->next();
                }
            }
        }
    }

    /**
     * Sort-merge NOT EXISTS: yield outer rows that have NO match in inner
     */
    private function sortMergeNotExists(\Iterator $outerIter, \Iterator $innerIter, string $outerCol, string $innerCol): Traversable
    {
        $limit = $this->getLimit();
        $offset = $this->getOffset();
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;

        while ($outerIter->valid()) {
            $outerRow = $outerIter->current();
            $outerKey = $outerRow->$outerCol;

            // Advance inner to catch up
            while ($innerIter->valid() && $innerIter->current()->$innerCol < $outerKey) {
                $innerIter->next();
            }

            // Check if there's a match
            $hasMatch = $innerIter->valid() && $innerIter->current()->$innerCol === $outerKey;

            if (!$hasMatch) {
                // No match - yield for NOT EXISTS
                if ($skipped++ >= $offset) {
                    yield $rowId++ => $outerRow;
                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }
            }

            $outerIter->next();
        }
    }

    /**
     * Block hash EXISTS: process outer in chunks, scan inner for each chunk
     *
     * Memory bounded to chunk size, trades memory for inner scans.
     */
    private function blockHashExists(): Traversable
    {
        $outerCols = array_map(fn($pair) => $pair[0], $this->correlations);
        $innerCols = array_map(fn($pair) => $pair[1], $this->correlations);
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        // TODO: Tune chunk size - can probably be 1000 or so
        $chunkSize = 64;
        $rowId = 0;
        $skipped = 0;
        $emitted = 0;

        // Process outer in chunks
        $chunk = [];        // rowId => row
        $chunkKeys = [];    // key => [rowIds...]
        $chunkMatched = []; // key => true (for EXISTS) or absent (for NOT EXISTS check)

        foreach ($this->outer as $outerRowId => $outerRow) {
            $key = $this->buildKey($outerRow, $outerCols);
            $chunk[$outerRowId] = $outerRow;
            $chunkKeys[$key][] = $outerRowId;

            if (count($chunk) >= $chunkSize) {
                // Scan inner to find matches
                foreach ($this->inner as $innerRow) {
                    $innerKey = $this->buildKey($innerRow, $innerCols);
                    if (isset($chunkKeys[$innerKey])) {
                        $chunkMatched[$innerKey] = true;
                    }
                }

                // Yield matching (or non-matching for NOT EXISTS) outer rows
                foreach ($chunk as $row) {
                    $key = $this->buildKey($row, $outerCols);
                    $exists = isset($chunkMatched[$key]);
                    if ($this->negated) {
                        $exists = !$exists;
                    }

                    if ($exists) {
                        if ($skipped++ < $offset) {
                            continue;
                        }
                        yield $rowId++ => $row;
                        if ($limit !== null && ++$emitted >= $limit) {
                            return;
                        }
                    }
                }

                // Clear chunk
                $chunk = [];
                $chunkKeys = [];
                $chunkMatched = [];
            }
        }

        // Process remaining rows in final chunk
        if (!empty($chunk)) {
            foreach ($this->inner as $innerRow) {
                $innerKey = $this->buildKey($innerRow, $innerCols);
                if (isset($chunkKeys[$innerKey])) {
                    $chunkMatched[$innerKey] = true;
                }
            }

            foreach ($chunk as $row) {
                $key = $this->buildKey($row, $outerCols);
                $exists = isset($chunkMatched[$key]);
                if ($this->negated) {
                    $exists = !$exists;
                }

                if ($exists) {
                    if ($skipped++ < $offset) {
                        continue;
                    }
                    yield $rowId++ => $row;
                    if ($limit !== null && ++$emitted >= $limit) {
                        return;
                    }
                }
            }
        }
    }

    /**
     * Build composite hash key from row values
     */
    private function buildKey(object $row, array $columns): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $parts[] = $row->$col ?? '';
        }
        return implode("\0", $parts);
    }

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }

    public function count(): int
    {
        return iterator_count($this);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Filter methods - push down to outer table
    // ─────────────────────────────────────────────────────────────────────────

    public function eq(string $column, mixed $value): TableInterface
    {
        return $this->withFilteredOuter($this->outer->eq($column, $value));
    }

    public function lt(string $column, mixed $value): TableInterface
    {
        return $this->withFilteredOuter($this->outer->lt($column, $value));
    }

    public function lte(string $column, mixed $value): TableInterface
    {
        return $this->withFilteredOuter($this->outer->lte($column, $value));
    }

    public function gt(string $column, mixed $value): TableInterface
    {
        return $this->withFilteredOuter($this->outer->gt($column, $value));
    }

    public function gte(string $column, mixed $value): TableInterface
    {
        return $this->withFilteredOuter($this->outer->gte($column, $value));
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return $this->withFilteredOuter($this->outer->in($column, $values));
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return $this->withFilteredOuter($this->outer->like($column, $pattern));
    }

    public function or(Predicate $a, Predicate $b, Predicate ...$more): TableInterface
    {
        return $this->withFilteredOuter($this->outer->or($a, $b, ...$more));
    }

    private function withFilteredOuter(TableInterface $filteredOuter): self
    {
        $new = new self($filteredOuter, $this->inner, $this->correlations, $this->negated);
        if ($this->getLimit() !== null) {
            $new = $new->limit($this->getLimit());
        }
        if ($this->getOffset() > 0) {
            $new = $new->offset($this->getOffset());
        }
        return $new;
    }
}
