<?php

namespace mini\Util\Math\Int;

/**
 * bcmath-based implementation of IntValue
 *
 * Stores normalized string internally.
 * Requires the bcmath extension.
 */
final class BcMathInt implements IntValue
{
    private function __construct(
        private readonly string $value
    ) {}

    public static function of(string|int $value): static
    {
        return new self(self::normalize((string) $value));
    }

    public static function zero(): static
    {
        return new self('0');
    }

    public static function one(): static
    {
        return new self('1');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic
    // ─────────────────────────────────────────────────────────────────────────

    public function add(string|int|IntValue $other): static
    {
        return new self(bcadd($this->value, $this->toStr($other), 0));
    }

    public function subtract(string|int|IntValue $other): static
    {
        return new self(bcsub($this->value, $this->toStr($other), 0));
    }

    public function multiply(string|int|IntValue $other): static
    {
        return new self(bcmul($this->value, $this->toStr($other), 0));
    }

    public function divide(string|int|IntValue $other): static
    {
        $divisor = $this->toStr($other);
        if (self::normalize($divisor) === '0') {
            throw new \DivisionByZeroError('Division by zero');
        }
        return new self(bcdiv($this->value, $divisor, 0));
    }

    public function modulus(string|int|IntValue $other): static
    {
        $divisor = $this->toStr($other);
        if (self::normalize($divisor) === '0') {
            throw new \DivisionByZeroError('Modulus by zero');
        }
        return new self(bcmod($this->value, $divisor, 0));
    }

    public function power(int $exponent): static
    {
        if ($exponent < 0) {
            throw new \InvalidArgumentException('Negative exponents not supported for integers');
        }
        return new self(bcpow($this->value, (string) $exponent, 0));
    }

    public function negate(): static
    {
        if ($this->value === '0') {
            return $this;
        }
        return new self(
            str_starts_with($this->value, '-')
                ? substr($this->value, 1)
                : '-' . $this->value
        );
    }

    public function absolute(): static
    {
        if ($this->isNegative()) {
            return $this->negate();
        }
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    public function compare(string|int|IntValue $other): int
    {
        return bccomp($this->value, $this->toStr($other), 0);
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
        return $this->value === '0';
    }

    public function isPositive(): bool
    {
        return $this->value !== '0' && !str_starts_with($this->value, '-');
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    public function toInt(): int
    {
        if (bccomp($this->value, (string) PHP_INT_MAX, 0) > 0
            || bccomp($this->value, (string) PHP_INT_MIN, 0) < 0) {
            throw new \OverflowException('Value exceeds native int range');
        }
        return (int) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────────────

    private function toStr(string|int|IntValue $value): string
    {
        if ($value instanceof self) {
            return $value->value;
        }
        return (string) $value;
    }

    /**
     * Normalize: strip +, leading zeros, handle -0
     */
    private static function normalize(string $value): string
    {
        $neg = false;
        $i = 0;
        $len = strlen($value);

        if ($i < $len && ($value[$i] === '-' || $value[$i] === '+')) {
            $neg = $value[$i] === '-';
            $i++;
        }

        while ($i < $len - 1 && $value[$i] === '0') {
            $i++;
        }

        $abs = $i === 0 ? $value : substr($value, $i);
        if ($abs === '' || $abs === false) {
            return '0';
        }

        if ($abs === '0') {
            return '0';
        }

        return $neg ? '-' . $abs : $abs;
    }
}
