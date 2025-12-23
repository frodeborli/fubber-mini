<?php

namespace mini\Util\Math\Int;

/**
 * Reference implementation using Python interpreter
 *
 * Delegates all arithmetic to Python's arbitrary precision integers.
 * Useful for testing correctness of other implementations.
 * NOT for production use - spawns a process per operation.
 */
final class PyInt implements IntValue
{
    private function __construct(
        private readonly string $value
    ) {}

    public static function of(string|int $value): static
    {
        return new self(self::py("print(int('{$value}'))"));
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
        $b = $this->toStr($other);
        return new self(self::py("print({$this->value} + int('{$b}'))"));
    }

    public function subtract(string|int|IntValue $other): static
    {
        $b = $this->toStr($other);
        return new self(self::py("print({$this->value} - int('{$b}'))"));
    }

    public function multiply(string|int|IntValue $other): static
    {
        $b = $this->toStr($other);
        return new self(self::py("print({$this->value} * int('{$b}'))"));
    }

    public function divide(string|int|IntValue $other): static
    {
        $b = self::py("print(int('{$this->toStr($other)}'))");
        if ($b === '0') {
            throw new \DivisionByZeroError('Division by zero');
        }
        // Python's // truncates toward negative infinity, but PHP truncates toward zero
        // Use: sign * (abs(a) // abs(b)) for PHP-compatible truncation
        $code = "a={$this->value}; b=int('{$b}'); s=(-1 if (a<0)!=(b<0) else 1); print(s*(abs(a)//abs(b)))";
        return new self(self::py($code));
    }

    public function modulus(string|int|IntValue $other): static
    {
        $b = self::py("print(int('{$this->toStr($other)}'))");
        if ($b === '0') {
            throw new \DivisionByZeroError('Modulus by zero');
        }
        // Python's % follows divisor sign, PHP's follows dividend sign
        // Use: a - truncdiv(a,b)*b where truncdiv truncates toward zero
        $code = "a={$this->value}; b=int('{$b}'); s=(-1 if (a<0)!=(b<0) else 1); q=s*(abs(a)//abs(b)); print(a - q*b)";
        return new self(self::py($code));
    }

    public function power(int $exponent): static
    {
        if ($exponent < 0) {
            throw new \InvalidArgumentException('Negative exponents not supported for integers');
        }
        return new self(self::py("print({$this->value} ** {$exponent})"));
    }

    public function negate(): static
    {
        if ($this->value === '0') {
            return $this;
        }
        return new self(self::py("print(-({$this->value}))"));
    }

    public function absolute(): static
    {
        return new self(self::py("print(abs({$this->value}))"));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    public function compare(string|int|IntValue $other): int
    {
        $b = $this->toStr($other);
        $result = self::py("a={$this->value}; b=int('{$b}'); print(-1 if a<b else (1 if a>b else 0))");
        return (int) $result;
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
        $max = (string) PHP_INT_MAX;
        $min = (string) PHP_INT_MIN;

        // Compare magnitudes to check overflow
        if ($this->isNegative()) {
            if ($this->compare($min) < 0) {
                throw new \OverflowException('Value exceeds native int range');
            }
        } else {
            if ($this->compare($max) > 0) {
                throw new \OverflowException('Value exceeds native int range');
            }
        }

        return (int) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function toStr(string|int|IntValue $value): string
    {
        return (string) $value;
    }

    private static function py(string $code): string
    {
        $result = shell_exec("python3 -c " . escapeshellarg($code) . " 2>&1");
        if ($result === null) {
            throw new \RuntimeException('Failed to execute Python');
        }
        return trim($result);
    }
}
