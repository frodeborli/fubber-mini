<?php

namespace mini\Database;

/**
 * Interface for objects that can convert themselves to SQL-bindable values
 *
 * Implement this interface on domain objects that need to be passed directly
 * to database queries. PartialQuery will call toSqlValue() automatically.
 *
 * ```php
 * class Money implements SqlValueInterface
 * {
 *     public function __construct(public readonly int $cents) {}
 *
 *     public function toSqlValue(): int
 *     {
 *         return $this->cents;
 *     }
 * }
 *
 * // Now works directly in queries
 * Order::query()->gt('total', new Money(1000));
 * ```
 *
 * For types you don't control (DateTime, etc.), register a converter:
 * ```php
 * $registry->register(function(\DateTimeInterface $dt): string {
 *     return $dt->format('Y-m-d H:i:s');
 * }, 'sql-value');
 * ```
 */
interface SqlValueInterface
{
    /**
     * Convert to a SQL-bindable scalar value
     */
    public function toSqlValue(): string|int|float|bool|null;
}
