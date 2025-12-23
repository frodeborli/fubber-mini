<?php

namespace mini\Util\Math\Int;

/**
 * Pure PHP arbitrary precision integer using decimal limbs (base 10^9)
 *
 * Stores numbers as array of 9-digit chunks internally.
 * Only converts to/from decimal string on I/O.
 *
 * This is the fastest pure PHP implementation - used as fallback
 * when GMP and bcmath extensions are not available.
 */
final class NativeInt implements IntValue
{
    private const CHUNK = 9;
    private const BASE = 1_000_000_000;  // 10^9

    private function __construct(
        /** Little-endian: limbs[0] is least significant */
        private readonly array $limbs,
        private readonly bool $negative = false,
    ) {}

    public static function of(string|int|IntValue $value): static
    {
        // Fast path: already a NativeInt, just return it (immutable)
        if ($value instanceof self) {
            return $value;
        }

        $str = (string) $value;
        $neg = false;

        if ($str !== '' && ($str[0] === '-' || $str[0] === '+')) {
            $neg = $str[0] === '-';
            $str = substr($str, 1);
        }

        $str = ltrim($str, '0') ?: '0';

        if ($str === '0') {
            return new self([0], false);
        }

        return new self(self::strToLimbs($str), $neg);
    }

    /**
     * Create directly from limbs (internal use, avoids conversion)
     * @param int[] $limbs Little-endian limbs
     */
    public static function fromLimbs(array $limbs, bool $negative = false): static
    {
        return new self(self::trimLimbs($limbs), $negative);
    }

    public static function zero(): static
    {
        return new self([0], false);
    }

    public static function one(): static
    {
        return new self([1], false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic
    // ─────────────────────────────────────────────────────────────────────────

    public function add(string|int|IntValue $other): static
    {
        $b = $other instanceof self ? $other : self::of($other);

        if ($this->negative === $b->negative) {
            return new self(self::addLimbs($this->limbs, $b->limbs), $this->negative);
        }

        $cmp = self::cmpLimbs($this->limbs, $b->limbs);
        if ($cmp === 0) {
            return self::zero();
        }
        if ($cmp > 0) {
            return new self(self::subLimbs($this->limbs, $b->limbs), $this->negative);
        }
        return new self(self::subLimbs($b->limbs, $this->limbs), $b->negative);
    }

    public function subtract(string|int|IntValue $other): static
    {
        $b = $other instanceof self ? $other : self::of($other);
        return $this->add(new self($b->limbs, !$b->negative));
    }

    public function multiply(string|int|IntValue $other): static
    {
        $b = $other instanceof self ? $other : self::of($other);

        if ($this->isZero() || $b->isZero()) {
            return self::zero();
        }

        // Fast path: single-limb multiplier
        if (count($b->limbs) === 1) {
            return new self(
                self::mulBySmall($this->limbs, $b->limbs[0]),
                $this->negative !== $b->negative
            );
        }
        if (count($this->limbs) === 1) {
            return new self(
                self::mulBySmall($b->limbs, $this->limbs[0]),
                $this->negative !== $b->negative
            );
        }

        return new self(
            self::mulLimbs($this->limbs, $b->limbs),
            $this->negative !== $b->negative
        );
    }

    public function divide(string|int|IntValue $other): static
    {
        $b = $other instanceof self ? $other : self::of($other);

        if ($b->isZero()) {
            throw new \DivisionByZeroError('Division by zero');
        }

        if ($this->isZero()) {
            return self::zero();
        }

        // Fast path: single-limb divisor
        if (count($b->limbs) === 1) {
            $divisor = $b->limbs[0];
            [$q, ] = self::divModBySmall($this->limbs, $divisor);
            return new self($q, $this->negative !== $b->negative);
        }

        $cmp = self::cmpLimbs($this->limbs, $b->limbs);
        if ($cmp < 0) {
            return self::zero();
        }

        [$q, ] = self::divModLimbs($this->limbs, $b->limbs);
        return new self($q, $this->negative !== $b->negative);
    }

    public function modulus(string|int|IntValue $other): static
    {
        $b = $other instanceof self ? $other : self::of($other);

        if ($b->isZero()) {
            throw new \DivisionByZeroError('Modulus by zero');
        }

        if ($this->isZero()) {
            return self::zero();
        }

        // Fast path: single-limb divisor
        if (count($b->limbs) === 1) {
            [, $r] = self::divModBySmall($this->limbs, $b->limbs[0]);
            return new self([$r], $this->negative);
        }

        [, $r] = self::divModLimbs($this->limbs, $b->limbs);
        return new self($r, $this->negative);
    }

    public function power(int $exponent): static
    {
        if ($exponent < 0) {
            throw new \InvalidArgumentException('Negative exponents not supported for integers');
        }
        if ($exponent === 0) {
            return self::one();
        }
        if ($exponent === 1) {
            return $this;
        }

        $result = self::one();
        $base = $this;

        while ($exponent > 0) {
            if ($exponent & 1) {
                $result = $result->multiply($base);
            }
            $base = $base->multiply($base);
            $exponent >>= 1;
        }

        return $result;
    }

    public function negate(): static
    {
        if ($this->isZero()) {
            return $this;
        }
        return new self($this->limbs, !$this->negative);
    }

    public function absolute(): static
    {
        return new self($this->limbs, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    public function compare(string|int|IntValue $other): int
    {
        $b = $other instanceof self ? $other : self::of($other);

        if ($this->isZero() && $b->isZero()) {
            return 0;
        }
        if ($this->negative !== $b->negative) {
            return $this->negative ? -1 : 1;
        }

        $cmp = self::cmpLimbs($this->limbs, $b->limbs);
        return $this->negative ? -$cmp : $cmp;
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
        return count($this->limbs) === 1 && $this->limbs[0] === 0;
    }

    public function isPositive(): bool
    {
        return !$this->isZero() && !$this->negative;
    }

    public function isNegative(): bool
    {
        return $this->negative && !$this->isZero();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    public function toInt(): int
    {
        $str = $this->__toString();
        if ($this->compare(PHP_INT_MAX) > 0 || $this->compare(PHP_INT_MIN) < 0) {
            throw new \OverflowException('Value exceeds native int range');
        }
        return (int) $str;
    }

    public function __toString(): string
    {
        if ($this->isZero()) {
            return '0';
        }

        $str = self::limbsToStr($this->limbs);
        return $this->negative ? '-' . $str : $str;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Limb operations
    // ─────────────────────────────────────────────────────────────────────────

    private static function cmpLimbs(array $a, array $b): int
    {
        $aLen = count($a);
        $bLen = count($b);

        if ($aLen !== $bLen) {
            return $aLen <=> $bLen;
        }

        for ($i = $aLen - 1; $i >= 0; $i--) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return 0;
    }

    private static function addLimbs(array $a, array $b): array
    {
        $aLen = count($a);
        $bLen = count($b);
        $maxLen = max($aLen, $bLen);

        $result = [];
        $carry = 0;

        for ($i = 0; $i < $maxLen || $carry; $i++) {
            $sum = $carry;
            $sum += $i < $aLen ? $a[$i] : 0;
            $sum += $i < $bLen ? $b[$i] : 0;

            $result[] = $sum % self::BASE;
            $carry = intdiv($sum, self::BASE);
        }

        return $result;
    }

    private static function subLimbs(array $a, array $b): array
    {
        // Assumes a >= b
        $aLen = count($a);
        $bLen = count($b);

        $result = [];
        $borrow = 0;

        for ($i = 0; $i < $aLen; $i++) {
            $diff = $a[$i] - ($i < $bLen ? $b[$i] : 0) - $borrow;

            if ($diff < 0) {
                $diff += self::BASE;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result[] = $diff;
        }

        return self::trimLimbs($result);
    }

    private static function mulLimbs(array $a, array $b): array
    {
        $aLen = count($a);
        $bLen = count($b);
        $result = array_fill(0, $aLen + $bLen, 0);

        for ($i = 0; $i < $aLen; $i++) {
            $carry = 0;
            for ($j = 0; $j < $bLen; $j++) {
                $prod = $a[$i] * $b[$j] + $result[$i + $j] + $carry;
                $result[$i + $j] = $prod % self::BASE;
                $carry = intdiv($prod, self::BASE);
            }
            // Propagate carry through remaining limbs
            $k = $i + $bLen;
            while ($carry) {
                if (!isset($result[$k])) {
                    $result[$k] = 0;
                }
                $sum = $result[$k] + $carry;
                $result[$k] = $sum % self::BASE;
                $carry = intdiv($sum, self::BASE);
                $k++;
            }
        }

        return self::trimLimbs($result);
    }

    /**
     * Fast multiplication by a single-limb value (< BASE)
     */
    private static function mulBySmall(array $a, int $multiplier): array
    {
        if ($multiplier === 0) {
            return [0];
        }
        if ($multiplier === 1) {
            return $a;
        }

        $result = [];
        $carry = 0;

        for ($i = 0; $i < count($a); $i++) {
            $prod = $a[$i] * $multiplier + $carry;
            $result[$i] = $prod % self::BASE;
            $carry = intdiv($prod, self::BASE);
        }

        if ($carry) {
            $result[] = $carry;
        }

        return $result;
    }

    /**
     * Fast division by a single-limb value (< BASE)
     *
     * @return array{array, int} [quotient limbs, remainder as int]
     */
    private static function divModBySmall(array $a, int $divisor): array
    {
        if ($divisor === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }

        $len = count($a);
        $quotient = array_fill(0, $len, 0);
        $remainder = 0;

        // Process from most significant to least significant
        for ($i = $len - 1; $i >= 0; $i--) {
            $current = $remainder * self::BASE + $a[$i];
            $quotient[$i] = intdiv($current, $divisor);
            $remainder = $current % $divisor;
        }

        return [self::trimLimbs($quotient), $remainder];
    }

    /**
     * @return array{array, array} [quotient, remainder]
     */
    private static function divModLimbs(array $a, array $b): array
    {
        $cmp = self::cmpLimbs($a, $b);
        if ($cmp < 0) {
            return [[0], $a];
        }
        if ($cmp === 0) {
            return [[1], [0]];
        }

        // Convert to strings for division (reuse existing logic)
        $aStr = self::limbsToStr($a);
        $bStr = self::limbsToStr($b);

        // Use long division
        $quotient = '';
        $remainder = '';
        $aLen = strlen($aStr);

        // Precompute b*1..b*9
        $multiples = ['0'];
        for ($d = 1; $d <= 9; $d++) {
            $multiples[$d] = self::mulByDigit($bStr, $d);
        }

        for ($i = 0; $i < $aLen; $i++) {
            $remainder .= $aStr[$i];
            $remainder = ltrim($remainder, '0') ?: '0';

            $q = 0;
            if ($remainder !== '0' && self::strCmp($remainder, $bStr) >= 0) {
                for ($d = 9; $d >= 1; $d--) {
                    if (self::strCmp($multiples[$d], $remainder) <= 0) {
                        $q = $d;
                        break;
                    }
                }
                $remainder = self::strSub($remainder, $multiples[$q]);
            }

            $quotient .= $q;
        }

        $quotient = ltrim($quotient, '0') ?: '0';
        $remainder = $remainder ?: '0';

        return [self::strToLimbs($quotient), self::strToLimbs($remainder)];
    }

    private static function trimLimbs(array $limbs): array
    {
        while (count($limbs) > 1 && $limbs[count($limbs) - 1] === 0) {
            array_pop($limbs);
        }
        return $limbs;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // String conversion
    // ─────────────────────────────────────────────────────────────────────────

    private static function strToLimbs(string $s): array
    {
        $limbs = [];
        $len = strlen($s);
        $pos = $len;

        while ($pos > 0) {
            $start = max(0, $pos - self::CHUNK);
            $limbs[] = (int) substr($s, $start, $pos - $start);
            $pos = $start;
        }

        return $limbs ?: [0];
    }

    private static function limbsToStr(array $limbs): string
    {
        $limbs = self::trimLimbs($limbs);

        if (count($limbs) === 1 && $limbs[0] === 0) {
            return '0';
        }

        $result = (string) $limbs[count($limbs) - 1];
        for ($i = count($limbs) - 2; $i >= 0; $i--) {
            $result .= str_pad((string) $limbs[$i], self::CHUNK, '0', STR_PAD_LEFT);
        }

        return $result;
    }

    // String helpers for division
    private static function strCmp(string $a, string $b): int
    {
        $aLen = strlen($a);
        $bLen = strlen($b);
        if ($aLen !== $bLen) return $aLen <=> $bLen;
        return strcmp($a, $b) <=> 0;
    }

    private static function strSub(string $a, string $b): string
    {
        $maxLen = max(strlen($a), strlen($b));
        $a = str_pad($a, $maxLen, '0', STR_PAD_LEFT);
        $b = str_pad($b, $maxLen, '0', STR_PAD_LEFT);

        $result = '';
        $borrow = 0;

        for ($i = $maxLen - 1; $i >= 0; $i--) {
            $diff = (int)$a[$i] - (int)$b[$i] - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function mulByDigit(string $a, int $d): string
    {
        if ($d === 0 || $a === '0') return '0';
        if ($d === 1) return $a;

        $result = '';
        $carry = 0;

        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $prod = (int)$a[$i] * $d + $carry;
            $result = ($prod % 10) . $result;
            $carry = intdiv($prod, 10);
        }

        if ($carry) $result = $carry . $result;
        return $result;
    }
}
