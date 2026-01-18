<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Utility\TablePropertiesTrait;
use stdClass;
use Traversable;

/**
 * Set difference table (rows in source but NOT in excluded set)
 *
 * Yields rows from source that don't exist in the excluded set.
 * Filter methods push down to source via AbstractTableWrapper.
 *
 * ```php
 * // WHERE id NOT IN (1, 2, 3)
 * $table->columns('id')->except(new Set('id', [1, 2, 3]))
 *
 * // WHERE status != 'inactive'
 * $table->except($table->eq('status', 'inactive'))
 * ```
 */
class ExceptTable extends AbstractTableWrapper
{
    public function __construct(
        TableInterface $source,
        private SetInterface $excluded,
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

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $excluded = $this->excluded;
        $excludedCols = array_keys($excluded->getColumns());
        $allAdditional = array_unique([...$additionalColumns, ...$excludedCols]);

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach (parent::materialize(...$allAdditional) as $id => $row) {
            // Build member object from excluded set's columns
            $member = new stdClass();
            foreach ($excludedCols as $col) {
                $member->$col = $row->$col ?? null;
            }

            // Skip if in excluded set
            if ($excluded->has($member)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            yield $id => $row;
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    public function count(): int
    {
        return iterator_count($this);
    }

    public function has(object $member): bool
    {
        // Short-circuit: if member is in excluded set, it's not in result
        $excludedCols = array_keys($this->excluded->getColumns());
        $excludedMember = new \stdClass();
        foreach ($excludedCols as $col) {
            if (!property_exists($member, $col)) {
                // Can't check exclusion - fall back to parent
                return parent::has($member);
            }
            $excludedMember->$col = $member->$col;
        }
        if ($this->excluded->has($excludedMember)) {
            return false;
        }
        return parent::has($member);
    }

}
