<?php

namespace mini\Util\Math\Int;

use Stringable;

/**
 * Immutable arbitrary precision integer value object
 *
 * All arithmetic operations return new instances.
 * Implementations may use GMP, bcmath, or pure PHP internally.
 */
interface IntValue extends Stringable
{
    /**
     * Create from string or int
     */
    public static function of(string|int $value): static;

    /**
     * Create zero
     */
    public static function zero(): static;

    /**
     * Create one
     */
    public static function one(): static;

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic operations (return new instance)
    // ─────────────────────────────────────────────────────────────────────────

    public function add(string|int|self $other): static;
    public function subtract(string|int|self $other): static;
    public function multiply(string|int|self $other): static;

    /**
     * Integer division (truncates toward zero)
     *
     * @throws \DivisionByZeroError
     */
    public function divide(string|int|self $other): static;

    /**
     * Remainder after integer division
     *
     * @throws \DivisionByZeroError
     */
    public function modulus(string|int|self $other): static;

    /**
     * Raise to integer power
     *
     * @throws \InvalidArgumentException if exponent is negative
     */
    public function power(int $exponent): static;

    /**
     * Negate: -x
     */
    public function negate(): static;

    /**
     * Absolute value
     */
    public function absolute(): static;

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compare: returns -1 if less, 0 if equal, 1 if greater
     */
    public function compare(string|int|self $other): int;

    public function equals(string|int|self $other): bool;
    public function lessThan(string|int|self $other): bool;
    public function greaterThan(string|int|self $other): bool;
    public function lessThanOrEqual(string|int|self $other): bool;
    public function greaterThanOrEqual(string|int|self $other): bool;

    // ─────────────────────────────────────────────────────────────────────────
    // Predicates
    // ─────────────────────────────────────────────────────────────────────────

    public function isZero(): bool;
    public function isPositive(): bool;
    public function isNegative(): bool;

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert to native int
     *
     * @throws \OverflowException if value exceeds PHP_INT_MAX/PHP_INT_MIN
     */
    public function toInt(): int;

    /**
     * Convert to string representation
     */
    public function __toString(): string;
}
