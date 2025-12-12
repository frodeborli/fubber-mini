<?php

namespace mini\Table;

use IteratorAggregate;
use stdClass;
use Traversable;

/**
 * Simple in-memory set implementation
 *
 * Provides O(1) membership testing via array key lookup.
 *
 * ```php
 * $set = new Set('status', ['active', 'pending']);
 * $set->has((object)['status' => 'active']);  // true
 * $set->has((object)['status' => 'deleted']); // false
 *
 * // Iteration yields stdClass
 * foreach ($set as $member) {
 *     echo $member->status;  // 'active', 'pending'
 * }
 * ```
 */
class Set implements SetInterface, IteratorAggregate
{
    /** @var array<string, true> Values stored as keys for O(1) lookup */
    private array $items = [];

    /**
     * @param string $column Column name for the values
     * @param array<int, string|int|float> $values Values in the set
     */
    public function __construct(
        private string $column,
        array $values,
    ) {
        foreach ($values as $value) {
            // Use string key for lookup (handles int/float/string)
            $this->items[(string)$value] = true;
        }
    }

    public function getColumns(): array
    {
        return [$this->column => new ColumnDef($this->column)];
    }

    public function has(object $member): bool
    {
        $col = $this->column;

        if (!property_exists($member, $col)) {
            throw new \InvalidArgumentException("Member missing property: $col");
        }

        return isset($this->items[(string)$member->$col]);
    }

    public function getIterator(): Traversable
    {
        $col = $this->column;

        foreach ($this->items as $key => $_) {
            $obj = new stdClass();

            // Try to return original type (int if numeric)
            if (is_numeric($key) && !str_contains($key, '.')) {
                $obj->$col = (int)$key;
            } else {
                $obj->$col = $key;
            }

            yield $obj;
        }
    }
}
