<?php

namespace mini\Util\Math;

use mini\Util\Math\Int\BcMathInt;
use mini\Util\Math\Int\GmpInt;
use mini\Util\Math\Int\IntValue;
use mini\Util\Math\Int\NativeInt;

/**
 * Immutable arbitrary precision integer
 *
 * This is the public API for arbitrary precision integer math.
 * Internally uses the best available implementation (GMP, bcmath, or pure PHP).
 *
 * Serialization stores only the string value, so serialized data is portable
 * across different PHP installations regardless of available extensions.
 *
 * Usage:
 *   $a = BigInt::of('123456789012345678901234567890');
 *   $b = BigInt::of(42);
 *   $result = $a->add($b)->multiply($a);
 *   echo $result; // prints the number
 */
final class BigInt implements NumberInterface
{
    private static ?string $implementation = null;

    private function __construct(
        private readonly IntValue $value
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Factory methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create from string or int
     */
    public static function of(string|int $value): self
    {
        return new self(self::createValue($value));
    }

    /**
     * Create zero
     */
    public static function zero(): self
    {
        return self::of(0);
    }

    /**
     * Create one
     */
    public static function one(): self
    {
        return self::of(1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic operations (return new instance)
    // ─────────────────────────────────────────────────────────────────────────

    public function add(self|string|int $other): self
    {
        return new self($this->value->add($this->unwrap($other)));
    }

    public function subtract(self|string|int $other): self
    {
        return new self($this->value->subtract($this->unwrap($other)));
    }

    public function multiply(self|string|int $other): self
    {
        return new self($this->value->multiply($this->unwrap($other)));
    }

    /**
     * Integer division (truncates toward zero)
     *
     * @throws \DivisionByZeroError
     */
    public function divide(self|string|int $other): self
    {
        return new self($this->value->divide($this->unwrap($other)));
    }

    /**
     * Remainder after integer division
     *
     * @throws \DivisionByZeroError
     */
    public function modulus(self|string|int $other): self
    {
        return new self($this->value->modulus($this->unwrap($other)));
    }

    /**
     * Raise to integer power
     *
     * @throws \InvalidArgumentException if exponent is negative
     */
    public function power(int $exponent): self
    {
        return new self($this->value->power($exponent));
    }

    /**
     * Negate: -x
     */
    public function negate(): self
    {
        return new self($this->value->negate());
    }

    /**
     * Absolute value
     */
    public function absolute(): self
    {
        return new self($this->value->absolute());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compare: returns -1 if less, 0 if equal, 1 if greater
     */
    public function compare(self|string|int $other): int
    {
        return $this->value->compare($this->unwrap($other));
    }

    public function equals(self|string|int $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function lessThan(self|string|int $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function greaterThan(self|string|int $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThanOrEqual(self|string|int $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function greaterThanOrEqual(self|string|int $other): bool
    {
        return $this->compare($other) >= 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Predicates
    // ─────────────────────────────────────────────────────────────────────────

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scale is always 0 for integers
     */
    public function scale(): int
    {
        return 0;
    }

    /**
     * Convert to native int
     *
     * @throws \OverflowException if value exceeds PHP_INT_MAX/PHP_INT_MIN
     */
    public function toInt(): int
    {
        return $this->value->toInt();
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Serialization (implementation-agnostic)
    // ─────────────────────────────────────────────────────────────────────────

    public function __serialize(): array
    {
        return ['v' => (string) $this->value];
    }

    public function __unserialize(array $data): void
    {
        // Use reflection to set readonly property during unserialization
        $ref = new \ReflectionProperty(self::class, 'value');
        $ref->setValue($this, self::createValue($data['v']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Implementation selection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the name of the current implementation
     */
    public static function getImplementation(): string
    {
        return self::$implementation ??= self::detectImplementation();
    }

    /**
     * Force a specific implementation (mainly for testing)
     *
     * @param 'gmp'|'bcmath'|'native'|null $impl
     */
    public static function setImplementation(?string $impl): void
    {
        self::$implementation = $impl;
    }

    private static function detectImplementation(): string
    {
        if (extension_loaded('gmp')) {
            return 'gmp';
        }
        if (extension_loaded('bcmath')) {
            return 'bcmath';
        }
        return 'native';
    }

    private static function createValue(string|int $value): IntValue
    {
        return match (self::getImplementation()) {
            'gmp' => GmpInt::of($value),
            'bcmath' => BcMathInt::of($value),
            default => NativeInt::of($value),
        };
    }

    /**
     * Unwrap BigInt to string for internal IntValue operations
     */
    private function unwrap(self|string|int $value): string
    {
        return $value instanceof self ? (string) $value->value : (string) $value;
    }
}
