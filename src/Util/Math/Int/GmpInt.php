<?php

namespace mini\Util\Math\Int;

/**
 * GMP-based implementation of IntValue
 *
 * Wraps \GMP internally for maximum performance with large numbers.
 * Requires the gmp extension.
 */
final class GmpInt implements IntValue
{
    private function __construct(
        private readonly \GMP $value
    ) {}

    public static function of(string|int $value): static
    {
        return new self(gmp_init($value));
    }

    public static function zero(): static
    {
        return new self(gmp_init(0));
    }

    public static function one(): static
    {
        return new self(gmp_init(1));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic
    // ─────────────────────────────────────────────────────────────────────────

    public function add(string|int|IntValue $other): static
    {
        return new self(gmp_add($this->value, $this->toGmp($other)));
    }

    public function subtract(string|int|IntValue $other): static
    {
        return new self(gmp_sub($this->value, $this->toGmp($other)));
    }

    public function multiply(string|int|IntValue $other): static
    {
        return new self(gmp_mul($this->value, $this->toGmp($other)));
    }

    public function divide(string|int|IntValue $other): static
    {
        $divisor = $this->toGmp($other);
        if (gmp_cmp($divisor, 0) === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }
        return new self(gmp_div_q($this->value, $divisor));
    }

    public function modulus(string|int|IntValue $other): static
    {
        $divisor = $this->toGmp($other);
        if (gmp_cmp($divisor, 0) === 0) {
            throw new \DivisionByZeroError('Modulus by zero');
        }
        // gmp_mod always returns non-negative, we need truncated modulus
        // a mod b = a - (a / b) * b
        $quotient = gmp_div_q($this->value, $divisor);
        return new self(gmp_sub($this->value, gmp_mul($quotient, $divisor)));
    }

    public function power(int $exponent): static
    {
        if ($exponent < 0) {
            throw new \InvalidArgumentException('Negative exponents not supported for integers');
        }
        return new self(gmp_pow($this->value, $exponent));
    }

    public function negate(): static
    {
        return new self(gmp_neg($this->value));
    }

    public function absolute(): static
    {
        return new self(gmp_abs($this->value));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    public function compare(string|int|IntValue $other): int
    {
        return gmp_cmp($this->value, $this->toGmp($other));
    }

    public function equals(string|int|IntValue $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function lessThan(string|int|IntValue $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function greaterThan(string|int|IntValue $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThanOrEqual(string|int|IntValue $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function greaterThanOrEqual(string|int|IntValue $other): bool
    {
        return $this->compare($other) >= 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Predicates
    // ─────────────────────────────────────────────────────────────────────────

    public function isZero(): bool
    {
        return gmp_cmp($this->value, 0) === 0;
    }

    public function isPositive(): bool
    {
        return gmp_cmp($this->value, 0) > 0;
    }

    public function isNegative(): bool
    {
        return gmp_cmp($this->value, 0) < 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    public function toInt(): int
    {
        if (gmp_cmp($this->value, PHP_INT_MAX) > 0 || gmp_cmp($this->value, PHP_INT_MIN) < 0) {
            throw new \OverflowException('Value exceeds native int range');
        }
        return gmp_intval($this->value);
    }

    public function __toString(): string
    {
        return gmp_strval($this->value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert any input to GMP, preserving GMP objects from same implementation
     */
    private function toGmp(string|int|IntValue $value): \GMP
    {
        if ($value instanceof self) {
            return $value->value;
        }
        return gmp_init((string) $value);
    }
}
