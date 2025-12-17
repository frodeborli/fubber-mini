<?php

namespace mini\Table;

use IteratorAggregate;
use Traversable;

/**
 * Wraps a SetInterface to remap column names
 *
 * Used when a subquery returns a different column name than the outer
 * query expects. For example:
 *
 * ```php
 * // Subquery: SELECT user_id FROM orders
 * // Outer: WHERE id IN (subquery)
 * // Need to map 'user_id' -> 'id' for has() calls
 *
 * $mapped = new ColumnMappedSet($subqueryResult, 'user_id', 'id');
 * $table->in('id', $mapped);
 * ```
 */
class ColumnMappedSet implements SetInterface, IteratorAggregate
{
    /**
     * @param SetInterface $source The source set to wrap
     * @param string $sourceColumn Column name in the source set
     * @param string $targetColumn Column name expected by the consumer
     */
    public function __construct(
        private SetInterface $source,
        private string $sourceColumn,
        private string $targetColumn,
    ) {}

    public function getColumns(): array
    {
        $sourceCols = $this->source->getColumns();
        if (!isset($sourceCols[$this->sourceColumn])) {
            throw new \LogicException(
                "Source set does not have column '{$this->sourceColumn}'"
            );
        }

        // Return with remapped column name
        return [$this->targetColumn => $sourceCols[$this->sourceColumn]];
    }

    public function has(object $member): bool
    {
        // Consumer passes member with target column name, we translate to source
        if (!property_exists($member, $this->targetColumn)) {
            throw new \InvalidArgumentException(
                "Member missing property: {$this->targetColumn}"
            );
        }

        $mapped = new \stdClass();
        $mapped->{$this->sourceColumn} = $member->{$this->targetColumn};

        return $this->source->has($mapped);
    }

    public function getIterator(): Traversable
    {
        // Yield rows with remapped column name
        foreach ($this->source as $row) {
            $mapped = new \stdClass();
            $mapped->{$this->targetColumn} = $row->{$this->sourceColumn};
            yield $mapped;
        }
    }
}
