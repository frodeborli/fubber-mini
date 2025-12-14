<?php

namespace mini\Table;

/**
 * Column definition with type and optional index metadata
 *
 * The type determines comparison semantics (whether to use collation).
 * When a column is the leading column of an index, set $index to the
 * index type and list any additional columns in $indexWith.
 *
 * Examples:
 * ```php
 * // Simple text column, no index
 * new ColumnDef('name')
 *
 * // Integer primary key
 * new ColumnDef('id', ColumnType::Int, IndexType::Primary)
 *
 * // Indexed text column
 * new ColumnDef('email', ColumnType::Text, IndexType::Unique)
 *
 * // Composite index on (org_id, user_id) - define on leading column
 * new ColumnDef('org_id', ColumnType::Int, IndexType::Index, 'user_id')
 *
 * // DateTime column (sorts correctly with binary comparison)
 * new ColumnDef('created_at', ColumnType::DateTime)
 * ```
 */
readonly class ColumnDef
{
    /** @var string[] Additional columns in composite index */
    public array $indexWith;

    /**
     * @param string $name Column name
     * @param ColumnType $type Data type for comparison semantics
     * @param IndexType $index Index type (None if not indexed)
     * @param string ...$indexWith Additional columns in composite index
     */
    public function __construct(
        public string $name,
        public ColumnType $type = ColumnType::Text,
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
     * Returns a ColumnDef with the same type, weaker index type, and common
     * prefix of indexWith columns.
     *
     * ```php
     * $a = new ColumnDef('id', ColumnType::Int, IndexType::Primary);
     * $b = new ColumnDef('id', ColumnType::Int, IndexType::Index);
     * $a->commonWith($b);  // ColumnDef('id', ColumnType::Int, IndexType::Index)
     *
     * $a = new ColumnDef('a', ColumnType::Int, IndexType::Index, 'b', 'c');
     * $b = new ColumnDef('a', ColumnType::Int, IndexType::Index, 'b', 'd');
     * $a->commonWith($b);  // ColumnDef('a', ColumnType::Int, IndexType::Index, 'b')
     * ```
     *
     * @throws \InvalidArgumentException if column names or types don't match
     */
    public function commonWith(self $other): self
    {
        if ($this->name !== $other->name) {
            throw new \InvalidArgumentException(
                "Cannot union columns with different names: {$this->name} vs {$other->name}"
            );
        }

        if ($this->type !== $other->type) {
            throw new \InvalidArgumentException(
                "Cannot union columns with different types: {$this->name} has {$this->type->name} vs {$other->type->name}"
            );
        }

        // Take the weaker index type
        $index = $this->index->weakerOf($other->index);

        if (!$index->isIndexed()) {
            return new self($this->name, $this->type);
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

        return new self($this->name, $this->type, $index, ...$commonIndexWith);
    }
}
