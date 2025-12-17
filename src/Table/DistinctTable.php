<?php

namespace mini\Table;

use mini\Table\Index\TreapIndex;
use Traversable;

/**
 * Table wrapper that removes duplicate rows
 *
 * Deduplication is based on the visible columns at the time distinct() was called.
 * Uses TreapIndex for O(1) duplicate detection during iteration.
 *
 * ```php
 * // Unique roles
 * $table->columns('role')->distinct();
 *
 * // Unique role+name combos, then project to role
 * $table->columns('role', 'name')->distinct()->columns('role');
 * ```
 */
class DistinctTable extends AbstractTableWrapper
{
    public function __construct(
        TableInterface $source,
    ) {
        // Wrap source with pagination in BarrierTable to prevent filter pushdown
        // from changing result set membership
        if ($source instanceof AbstractTable && ($source->getLimit() !== null || $source->getOffset() > 0)) {
            $source = BarrierTable::from($source);
        }

        parent::__construct($source);
    }

    // -------------------------------------------------------------------------
    // Limit/offset must be stored locally, not pushed to source
    // (we deduplicate first, then apply pagination)
    // -------------------------------------------------------------------------

    public function limit(?int $n): TableInterface
    {
        if ($this->limit === $n) {
            return $this;
        }
        $c = clone $this;
        $c->limit = $n;
        return $c;
    }

    public function offset(int $n): TableInterface
    {
        if ($this->offset === $n) {
            return $this;
        }
        $c = clone $this;
        $c->offset = $n;
        return $c;
    }

    /**
     * Distinct of distinct is still distinct - return self
     */
    public function distinct(): TableInterface
    {
        return $this;
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $seen = new TreapIndex();
        $visibleCols = array_keys($this->getColumns());

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach (parent::materialize(...$additionalColumns) as $id => $row) {
            // Build key from visible columns only
            $key = $this->rowKey($row, $visibleCols);

            // Skip if already seen
            if ($seen->has($key)) {
                continue;
            }
            $seen->insert($key, 0);

            // Handle offset
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            yield $id => $row;
            $emitted++;

            // Handle limit
            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    /**
     * Generate a unique key for a row based on visible columns
     */
    private function rowKey(object $row, array $cols): string
    {
        $parts = [];
        foreach ($cols as $col) {
            $val = $row->$col ?? null;
            // Type prefix to distinguish null from "null" string
            $parts[] = ($val === null ? "\x00" : "\x01") . $val;
        }
        return implode("\x00", $parts);
    }

    public function count(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }
        $count = iterator_count($this);
        return $this->cachedCount = $count;
    }
}
