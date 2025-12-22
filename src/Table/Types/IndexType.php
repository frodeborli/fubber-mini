<?php

namespace mini\Table\Types;

/**
 * Type of index on a column
 */
enum IndexType: string
{
    /** No index */
    case None = 'none';

    /** Regular index - can filter and sort efficiently */
    case Index = 'index';

    /** Unique index - guarantees no duplicates */
    case Unique = 'unique';

    /** Primary key - unique and identifies the row */
    case Primary = 'primary';

    /**
     * Whether this column has any index (can filter/sort efficiently)
     */
    public function isIndexed(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether this index guarantees uniqueness
     */
    public function isUnique(): bool
    {
        return $this === self::Unique || $this === self::Primary;
    }

    /**
     * Get the weaker of two index types (for UNION)
     *
     * Strength order: None < Index < Unique < Primary
     */
    public function weakerOf(self $other): self
    {
        $strength = ['none' => 0, 'index' => 1, 'unique' => 2, 'primary' => 3];
        return $strength[$this->value] <= $strength[$other->value] ? $this : $other;
    }
}
