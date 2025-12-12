<?php

namespace mini\Table;

/**
 * Column definition with optional index metadata
 *
 * When a column is the leading column of an index, set $index to the
 * index type and list any additional columns in $indexWith.
 *
 * Examples:
 * ```php
 * // Simple column, no index
 * new ColumnDef('name')
 *
 * // Primary key
 * new ColumnDef('id', IndexType::Primary)
 *
 * // Composite index on (org_id, user_id) - define on leading column
 * new ColumnDef('org_id', IndexType::Index, 'user_id')
 *
 * // The non-leading column has no index info
 * new ColumnDef('user_id')
 * ```
 */
readonly class ColumnDef
{
    /** @var string[] Additional columns in composite index */
    public array $indexWith;

    /**
     * @param string $name Column name
     * @param IndexType $index Index type (None if not indexed)
     * @param string ...$indexWith Additional columns in composite index
     */
    public function __construct(
        public string $name,
        public IndexType $index = IndexType::None,
        string ...$indexWith,
    ) {
        $this->indexWith = $indexWith;
    }

    /**
     * Get all columns in this index (including this column)
     *
     * @return string[] Column names in index order, or empty if not indexed
     */
    public function getIndexColumns(): array
    {
        if (!$this->index->isIndexed()) {
            return [];
        }
        return [$this->name, ...$this->indexWith];
    }

    /**
     * Check if this index can efficiently handle the given order columns
     *
     * The index can handle ordering if the order columns are a prefix of
     * the index columns.
     *
     * @param string[] $orderColumns Column names in order
     */
    public function canOrder(array $orderColumns): bool
    {
        if (!$this->index->isIndexed()) {
            return false;
        }

        $indexCols = $this->getIndexColumns();

        // Order columns must be a prefix of index columns
        if (count($orderColumns) > count($indexCols)) {
            return false;
        }

        return array_slice($indexCols, 0, count($orderColumns)) === $orderColumns;
    }

    /**
     * Get the common denominator ColumnDef
     *
     * Returns a ColumnDef with the weaker index type and common prefix
     * of indexWith columns.
     *
     * ```php
     * $a = new ColumnDef('id', IndexType::Primary);
     * $b = new ColumnDef('id', IndexType::Index);
     * $a->commonWith($b);  // ColumnDef('id', IndexType::Index)
     *
     * $a = new ColumnDef('a', IndexType::Index, 'b', 'c');
     * $b = new ColumnDef('a', IndexType::Index, 'b', 'd');
     * $a->commonWith($b);  // ColumnDef('a', IndexType::Index, 'b')
     *
     * $a = new ColumnDef('name', IndexType::Index);
     * $b = new ColumnDef('name');
     * $a->commonWith($b);  // ColumnDef('name')
     * ```
     *
     * @throws \InvalidArgumentException if column names don't match
     */
    public function commonWith(self $other): self
    {
        if ($this->name !== $other->name) {
            throw new \InvalidArgumentException(
                "Cannot union columns with different names: {$this->name} vs {$other->name}"
            );
        }

        // Take the weaker index type
        $index = $this->index->weakerOf($other->index);

        if (!$index->isIndexed()) {
            return new self($this->name);
        }

        // Find common prefix of indexWith
        $commonIndexWith = [];
        $minLen = min(count($this->indexWith), count($other->indexWith));
        for ($i = 0; $i < $minLen; $i++) {
            if ($this->indexWith[$i] === $other->indexWith[$i]) {
                $commonIndexWith[] = $this->indexWith[$i];
            } else {
                break;
            }
        }

        return new self($this->name, $index, ...$commonIndexWith);
    }
}
