<?php

namespace mini\Database;

/**
 * Interface for classes that can hydrate themselves from a single SQL column value
 *
 * Implement this interface on value objects that map to a single database column.
 * The converter registry will automatically call fromSqlValue() during entity hydration.
 *
 * ```php
 * class Money implements SqlValue, SqlValueHydrator
 * {
 *     public function __construct(public readonly int $cents) {}
 *
 *     // SQL column → PHP (hydration)
 *     public static function fromSqlValue(string|int|float|bool $value): static
 *     {
 *         return new static((int) $value);
 *     }
 *
 *     // PHP → SQL column (queries)
 *     public function toSqlValue(): int
 *     {
 *         return $this->cents;
 *     }
 * }
 *
 * // Now works automatically in entities
 * class Order {
 *     public int $id;
 *     public Money $total;  // Hydrated from integer column
 * }
 * ```
 *
 * @see Hydration For hydrating from a full database row
 * @see SqlValue For the reverse direction (PHP → SQL)
 */
interface SqlValueHydrator
{
    /**
     * Create instance from a single SQL column value
     *
     * @param string|int|float|bool $value The raw database column value
     * @return static
     */
    public static function fromSqlValue(string|int|float|bool $value): static;
}
