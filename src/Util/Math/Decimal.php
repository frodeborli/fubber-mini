<?php

namespace mini\Util\Math;

/**
 * Immutable fixed-point decimal with BigInt backing
 *
 * Stores value as scaled BigInt internally. Scale determines decimal places.
 * Designed to align with SQL DECIMAL semantics for use in Table ColumnDef types.
 *
 * Precision model:
 * - Basic arithmetic (+, -, *, /, %) is exact within the specified scale
 * - Transcendental functions (sqrt, exp, ln, pow) use iterative algorithms
 *   (Newton-Raphson, Taylor series) computed to configurable precision
 *
 * Accepts BigInt|int|float|string for arithmetic operations.
 *
 * Usage:
 *   $a = Decimal::of('123.45', 2);      // scale 2
 *   $b = Decimal::of('10', 4);          // 10.0000, scale 4
 *   $result = $a->add($b)->multiply(2);
 *   echo $result;                        // prints decimal string
 */
final class Decimal implements NumberInterface
{
    private function __construct(
        private readonly BigInt $unscaled,
        private readonly int $scale
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Factory methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create from string/int with specified scale
     *
     * The input value is adjusted to match the target scale:
     * - Decimal::of('123.45', 2)  → unscaled=12345, scale=2
     * - Decimal::of('123.45', 4)  → unscaled=1234500, scale=4
     * - Decimal::of('123', 2)     → unscaled=12300, scale=2
     * - Decimal::of(100, 2)       → unscaled=10000, scale=2
     *
     * @param int $scale Number of decimal places (must be >= 0)
     */
    public static function of(string|int $value, int $scale = 0): self
    {
        if ($scale < 0) {
            throw new \InvalidArgumentException('Scale must be non-negative');
        }

        if (is_int($value)) {
            // Integer: just scale up
            $unscaled = BigInt::of($value);
            if ($scale > 0) {
                $unscaled = $unscaled->multiply(self::powerOfTen($scale));
            }
            return new self($unscaled, $scale);
        }

        // Parse string
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Empty string is not a valid decimal');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-+');

        $pos = strpos($value, '.');
        if ($pos === false) {
            // No decimal point - treat as integer
            $intPart = $value;
            $inputScale = 0;
        } else {
            $intPart = substr($value, 0, $pos);
            $fracPart = substr($value, $pos + 1);
            $inputScale = strlen($fracPart);
            $value = $intPart . $fracPart;
        }

        // Remove leading zeros but keep at least one digit
        $value = ltrim($value, '0') ?: '0';

        $unscaled = BigInt::of($negative && $value !== '0' ? '-' . $value : $value);

        // Adjust to target scale
        if ($inputScale < $scale) {
            $unscaled = $unscaled->multiply(self::powerOfTen($scale - $inputScale));
        } elseif ($inputScale > $scale) {
            // Round to target scale
            $divisor = self::powerOfTen($inputScale - $scale);
            $unscaled = self::roundedDivide($unscaled, BigInt::of($divisor));
        }

        return new self($unscaled, $scale);
    }

    /**
     * Create zero with specified scale
     */
    public static function zero(int $scale = 0): self
    {
        return new self(BigInt::zero(), $scale);
    }

    /**
     * Create one with specified scale
     */
    public static function one(int $scale = 0): self
    {
        return new self(BigInt::of(self::powerOfTen($scale)), $scale);
    }

    /**
     * Parse string/int with auto-detected scale
     *
     * Unlike of(), this doesn't require specifying scale:
     * - Decimal::parse('123.45')  → scale 2
     * - Decimal::parse('100')     → scale 0
     * - Decimal::parse(42)        → scale 0
     */
    public static function parse(string|int $value): self
    {
        if (is_int($value)) {
            return new self(BigInt::of($value), 0);
        }

        // Detect scale from string
        $value = trim($value);
        $pos = strpos($value, '.');
        $scale = $pos === false ? 0 : strlen($value) - $pos - 1;

        return self::of($value, $scale);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add: result scale = max(this.scale, other.scale)
     */
    public function add(BigInt|self|string|int|float $other): self
    {
        $other = $this->coerce($other);
        [$a, $b, $scale] = $this->alignScales($other);
        return new self($a->add($b), $scale);
    }

    /**
     * Subtract: result scale = max(this.scale, other.scale)
     */
    public function subtract(BigInt|self|string|int|float $other): self
    {
        $other = $this->coerce($other);
        [$a, $b, $scale] = $this->alignScales($other);
        return new self($a->subtract($b), $scale);
    }

    /**
     * Multiply: result scale = this.scale + other.scale
     */
    public function multiply(BigInt|self|string|int|float $other): self
    {
        $other = $this->coerce($other);
        return new self(
            $this->unscaled->multiply($other->unscaled),
            $this->scale + $other->scale
        );
    }

    /**
     * Divide with specified result scale
     *
     * If scale is not specified, uses max(this.scale, other.scale) + 6
     * to provide reasonable precision for most cases.
     *
     * @throws \DivisionByZeroError
     */
    public function divide(BigInt|self|string|int|float $other, ?int $scale = null): self
    {
        $other = $this->coerce($other);

        if ($other->isZero()) {
            throw new \DivisionByZeroError('Division by zero');
        }

        // Default scale: max of operand scales + 6 extra digits
        $resultScale = $scale ?? max($this->scale, $other->scale) + 6;

        // To get correct result scale:
        // result = (a.unscaled * 10^resultScale) / (b.unscaled * 10^(a.scale - b.scale))
        // Simplified: result = (a.unscaled * 10^(resultScale + b.scale - a.scale)) / b.unscaled

        $scaleAdjust = $resultScale + $other->scale - $this->scale;
        $dividend = $this->unscaled;
        if ($scaleAdjust > 0) {
            $dividend = $dividend->multiply(self::powerOfTen($scaleAdjust));
        } elseif ($scaleAdjust < 0) {
            $dividend = $dividend->divide(self::powerOfTen(-$scaleAdjust));
        }

        $quotient = self::roundedDivide($dividend, $other->unscaled);
        return new self($quotient, $resultScale);
    }

    /**
     * Modulus: result scale = max(this.scale, other.scale)
     *
     * @throws \DivisionByZeroError
     */
    public function modulus(BigInt|self|string|int|float $other): self
    {
        $other = $this->coerce($other);

        if ($other->isZero()) {
            throw new \DivisionByZeroError('Modulus by zero');
        }

        [$a, $b, $scale] = $this->alignScales($other);
        return new self($a->modulus($b), $scale);
    }

    /**
     * Negate: -x
     */
    public function negate(): self
    {
        return new self($this->unscaled->negate(), $this->scale);
    }

    /**
     * Absolute value
     */
    public function absolute(): self
    {
        return new self($this->unscaled->absolute(), $this->scale);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transcendental functions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Square root using Newton-Raphson iteration
     *
     * Computes √x to the specified precision (scale).
     *
     * @param int|null $scale Result precision (defaults to current scale + 10)
     * @throws \InvalidArgumentException if value is negative
     */
    public function sqrt(?int $scale = null): self
    {
        if ($this->isNegative()) {
            throw new \InvalidArgumentException('Cannot compute square root of negative number');
        }
        if ($this->isZero()) {
            return self::zero($scale ?? $this->scale);
        }

        $scale ??= $this->scale + 10;
        // Work with extra precision internally
        $workScale = $scale + 10;

        // Initial guess: use float sqrt for reasonable starting point
        $floatVal = $this->toFloat();
        $guess = self::parse((string) sqrt($floatVal))->rescale($workScale);

        // Newton-Raphson: x_{n+1} = (x_n + S/x_n) / 2
        $two = self::of(2, 0);
        $target = $this->rescale($workScale);

        // Iterate until convergence
        $prev = null;
        $maxIter = $workScale + 20; // More iterations for higher precision
        for ($i = 0; $i < $maxIter; $i++) {
            $next = $guess->add($target->divide($guess, $workScale))->divide($two, $workScale);

            // Check convergence
            if ($prev !== null && $next->equals($prev)) {
                break;
            }
            $prev = $guess;
            $guess = $next;
        }

        return $guess->rescale($scale);
    }

    /**
     * Exponential function e^x using Taylor series
     *
     * Uses range reduction for efficiency: e^x = (e^(x/2^k))^(2^k)
     *
     * @param int|null $scale Result precision (defaults to 20)
     */
    public function exp(?int $scale = null): self
    {
        $scale ??= 20;
        $workScale = $scale + 15;

        // Handle zero
        if ($this->isZero()) {
            return self::one($scale);
        }

        // For negative x: e^(-x) = 1 / e^x
        if ($this->isNegative()) {
            return $this->negate()->exp($workScale)->reciprocal($scale);
        }

        $x = $this->rescale($workScale);

        // Range reduction: reduce x to small value for faster convergence
        // Find k such that x / 2^k < 1
        $k = 0;
        $reduced = $x;
        $one = self::one($workScale);
        $two = self::of(2, 0);

        while ($reduced->greaterThan($one)) {
            $reduced = $reduced->divide($two, $workScale);
            $k++;
        }

        // Taylor series: e^x = 1 + x + x²/2! + x³/3! + ...
        $result = $one;
        $term = $one;
        $n = 1;
        $maxTerms = $workScale * 3; // Sufficient terms for convergence

        while ($n < $maxTerms) {
            $term = $term->multiply($reduced)->divide($n, $workScale);
            if ($term->isZero()) {
                break;
            }
            $result = $result->add($term);
            $n++;
        }

        // Square back up: result = result^(2^k)
        for ($i = 0; $i < $k; $i++) {
            $result = $result->multiply($result)->rescale($workScale);
        }

        return $result->rescale($scale);
    }

    /**
     * Natural logarithm using Newton-Raphson on exp
     *
     * Solves exp(y) = x for y using iteration:
     * y_{n+1} = y_n + (x - exp(y_n)) / exp(y_n)
     *         = y_n - 1 + x / exp(y_n)
     *
     * @param int|null $scale Result precision (defaults to 20)
     * @throws \InvalidArgumentException if value is not positive
     */
    public function ln(?int $scale = null): self
    {
        if (!$this->isPositive()) {
            throw new \InvalidArgumentException('Logarithm requires positive value');
        }

        $scale ??= 20;
        $workScale = $scale + 15;

        $one = self::one($workScale);

        // Check for ln(1) = 0
        if ($this->equals($one)) {
            return self::zero($scale);
        }

        // Range reduction using ln(x * 2^k) = ln(x) + k*ln(2)
        // Reduce x to range [1, 2) for faster convergence
        $x = $this->rescale($workScale);
        $k = 0;
        $two = self::of(2, 0);

        while ($x->greaterThan($two)) {
            $x = $x->divide($two, $workScale);
            $k++;
        }
        while ($x->lessThan($one)) {
            $x = $x->multiply($two);
            $k--;
        }

        // Initial guess using float
        $floatVal = $x->toFloat();
        $guess = self::parse((string) log($floatVal))->rescale($workScale);

        // Newton-Raphson iteration
        $maxIter = $workScale + 20;
        for ($i = 0; $i < $maxIter; $i++) {
            $expGuess = $guess->exp($workScale);
            // y_{n+1} = y_n - 1 + x/exp(y_n)
            $next = $guess->subtract($one)->add($x->divide($expGuess, $workScale));

            // Check convergence
            if ($next->rescale($scale)->equals($guess->rescale($scale))) {
                $guess = $next;
                break;
            }
            $guess = $next;
        }

        // Add back the range reduction: ln(x) + k*ln(2)
        if ($k !== 0) {
            // ln(2) ≈ 0.693147180559945...
            // Using rational approximation: 25469/36744
            $ln2 = self::of('25469', 0)->divide(self::of('36744', 0), $workScale);
            $guess = $guess->add($ln2->multiply($k));
        }

        return $guess->rescale($scale);
    }

    /**
     * Reciprocal: 1/x
     *
     * @param int|null $scale Result precision
     * @throws \DivisionByZeroError if value is zero
     */
    public function reciprocal(?int $scale = null): self
    {
        if ($this->isZero()) {
            throw new \DivisionByZeroError('Cannot compute reciprocal of zero');
        }
        $scale ??= $this->scale + 10;
        return self::one(0)->divide($this, $scale);
    }

    /**
     * Power with arbitrary exponent: x^y = exp(y * ln(x))
     *
     * For integer exponents, uses repeated multiplication (faster).
     * For fractional exponents, uses exp(y * ln(x)).
     *
     * @param int|null $scale Result precision (null = natural scale for integers, 20 for fractional)
     * @throws \InvalidArgumentException if base is negative and exponent is fractional
     */
    public function pow(self|int|string $exponent, ?int $scale = null): self
    {
        // Handle integer exponent (fast path)
        if (is_int($exponent)) {
            return $this->intPow($exponent, $scale);
        }

        $exp = $exponent instanceof self ? $exponent : self::parse((string) $exponent);

        // Check if exponent is an integer
        $intPart = $exp->rescale(0);
        if ($exp->equals($intPart)) {
            return $this->intPow((int) (string) $intPart->unscaledValue(), $scale);
        }

        // Fractional exponent: x^y = exp(y * ln(x))
        if (!$this->isPositive()) {
            throw new \InvalidArgumentException('Negative base with fractional exponent is not supported');
        }

        $scale ??= 20;
        $workScale = $scale + 10;
        $result = $exp->rescale($workScale)
            ->multiply($this->ln($workScale))
            ->exp($scale);

        return $result;
    }

    /**
     * Integer power using binary exponentiation
     *
     * For positive exponents with integer base, preserves exact integer result.
     * Uses BigInt::power() for efficiency when possible.
     *
     * @param int|null $scale Result scale (null = preserve natural scale)
     */
    private function intPow(int $exponent, ?int $scale): self
    {
        // Natural scale for integer power: base.scale * |exponent|
        $naturalScale = $this->scale * abs($exponent);

        if ($exponent === 0) {
            return self::one($scale ?? 0);
        }

        $negative = $exponent < 0;
        $exp = abs($exponent);

        if (!$negative) {
            // For positive integer exponents, use exact BigInt arithmetic
            $unscaled = $this->unscaled->power($exp);
            $result = new self($unscaled, $naturalScale);

            // Only rescale if explicitly requested
            if ($scale !== null && $scale !== $naturalScale) {
                return $result->rescale($scale);
            }
            return $result;
        }

        // Negative exponent: x^(-n) = 1 / x^n
        $resultScale = $scale ?? 20;
        $positive = $this->intPow($exp, null);
        return $positive->reciprocal($resultScale);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison (works across scales)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compare: returns -1 if less, 0 if equal, 1 if greater
     *
     * Comparison is by numeric value, not representation:
     * Decimal::of('1.00', 2)->compare('1') === 0
     */
    public function compare(BigInt|self|string|int|float $other): int
    {
        $other = $this->coerce($other);
        [$a, $b, $_] = $this->alignScales($other);
        return $a->compare($b);
    }

    /**
     * High-performance static comparison of decimal strings
     *
     * Compares two decimal string values without object allocation.
     * Uses bccomp when available, pure PHP fallback otherwise.
     *
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    public static function compareStr(string $a, string $b): int
    {
        // Use bccomp if available (faster, handles edge cases)
        if (function_exists('bccomp')) {
            $scaleA = ($pos = strpos($a, '.')) === false ? 0 : strlen($a) - $pos - 1;
            $scaleB = ($pos = strpos($b, '.')) === false ? 0 : strlen($b) - $pos - 1;
            return bccomp($a, $b, max($scaleA, $scaleB));
        }

        // Pure PHP fallback
        $aNeg = str_starts_with($a, '-');
        $bNeg = str_starts_with($b, '-');

        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }

        $aAbs = ltrim($a, '-+');
        $bAbs = ltrim($b, '-+');

        $aPos = strpos($aAbs, '.');
        $bPos = strpos($bAbs, '.');

        $aInt = $aPos === false ? $aAbs : substr($aAbs, 0, $aPos);
        $aDec = $aPos === false ? '' : substr($aAbs, $aPos + 1);
        $bInt = $bPos === false ? $bAbs : substr($bAbs, 0, $bPos);
        $bDec = $bPos === false ? '' : substr($bAbs, $bPos + 1);

        $aInt = ltrim($aInt, '0') ?: '0';
        $bInt = ltrim($bInt, '0') ?: '0';

        if (strlen($aInt) !== strlen($bInt)) {
            $cmp = strlen($aInt) <=> strlen($bInt);
        } else {
            $cmp = strcmp($aInt, $bInt);
        }

        if ($cmp === 0) {
            $maxLen = max(strlen($aDec), strlen($bDec));
            $cmp = strcmp(str_pad($aDec, $maxLen, '0'), str_pad($bDec, $maxLen, '0'));
        }

        return $aNeg ? -$cmp : $cmp;
    }

    public function equals(BigInt|self|string|int|float $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function lessThan(BigInt|self|string|int|float $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function greaterThan(BigInt|self|string|int|float $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThanOrEqual(BigInt|self|string|int|float $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function greaterThanOrEqual(BigInt|self|string|int|float $other): bool
    {
        return $this->compare($other) >= 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Predicates
    // ─────────────────────────────────────────────────────────────────────────

    public function isZero(): bool
    {
        return $this->unscaled->isZero();
    }

    public function isPositive(): bool
    {
        return $this->unscaled->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->unscaled->isNegative();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scale operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the scale (number of decimal places)
     */
    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Get the unscaled BigInt value
     */
    public function unscaledValue(): BigInt
    {
        return $this->unscaled;
    }

    /**
     * Change scale with rounding
     */
    public function rescale(int $newScale, int $mode = PHP_ROUND_HALF_UP): self
    {
        if ($newScale < 0) {
            throw new \InvalidArgumentException('Scale must be non-negative');
        }

        if ($newScale === $this->scale) {
            return $this;
        }

        if ($newScale > $this->scale) {
            // Scale up - no rounding needed
            $multiplier = self::powerOfTen($newScale - $this->scale);
            return new self($this->unscaled->multiply($multiplier), $newScale);
        }

        // Scale down - need to round
        $divisor = BigInt::of(self::powerOfTen($this->scale - $newScale));
        $rounded = self::roundedDivide($this->unscaled, $divisor, $mode);
        return new self($rounded, $newScale);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert to string representation
     *
     * Always shows exactly `scale` decimal places:
     * - scale=2: "123.45", "0.05", "100.00"
     * - scale=0: "123", "0", "100"
     */
    public function __toString(): string
    {
        $str = (string) $this->unscaled->absolute();

        if ($this->scale === 0) {
            $result = $str;
        } else {
            // Pad with leading zeros if needed
            if (strlen($str) <= $this->scale) {
                $str = str_pad($str, $this->scale + 1, '0', STR_PAD_LEFT);
            }

            $intPart = substr($str, 0, -$this->scale);
            $fracPart = substr($str, -$this->scale);
            $result = $intPart . '.' . $fracPart;
        }

        return $this->unscaled->isNegative() ? '-' . $result : $result;
    }

    /**
     * Convert to float (may lose precision)
     */
    public function toFloat(): float
    {
        return (float) $this->__toString();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Serialization
    // ─────────────────────────────────────────────────────────────────────────

    public function __serialize(): array
    {
        return ['v' => (string) $this->unscaled, 's' => $this->scale];
    }

    public function __unserialize(array $data): void
    {
        $ref = new \ReflectionProperty(self::class, 'unscaled');
        $ref->setValue($this, BigInt::of($data['v']));

        $ref = new \ReflectionProperty(self::class, 'scale');
        $ref->setValue($this, $data['s']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Coerce input to Decimal
     */
    private function coerce(BigInt|self|string|int|float $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value instanceof BigInt) {
            return new self($value, 0);
        }
        if (is_float($value)) {
            // Convert float to string to preserve decimal representation
            $value = (string) $value;
        }
        // Auto-detect scale from input
        return self::parse($value);
    }

    /**
     * Align two decimals to the same scale
     *
     * @return array{BigInt, BigInt, int} [this.unscaled adjusted, other.unscaled adjusted, common scale]
     */
    private function alignScales(self $other): array
    {
        if ($this->scale === $other->scale) {
            return [$this->unscaled, $other->unscaled, $this->scale];
        }

        $maxScale = max($this->scale, $other->scale);

        $a = $this->scale < $maxScale
            ? $this->unscaled->multiply(self::powerOfTen($maxScale - $this->scale))
            : $this->unscaled;

        $b = $other->scale < $maxScale
            ? $other->unscaled->multiply(self::powerOfTen($maxScale - $other->scale))
            : $other->unscaled;

        return [$a, $b, $maxScale];
    }

    /**
     * Get 10^n as string (for BigInt operations)
     */
    private static function powerOfTen(int $n): string
    {
        return '1' . str_repeat('0', $n);
    }

    /**
     * Divide with rounding (half up by default)
     */
    private static function roundedDivide(BigInt $dividend, BigInt $divisor, int $mode = PHP_ROUND_HALF_UP): BigInt
    {
        $quotient = $dividend->divide($divisor);
        $remainder = $dividend->modulus($divisor);

        if ($remainder->isZero()) {
            return $quotient;
        }

        // Check if we need to round up
        $absRemainder = $remainder->absolute();
        $absDivisor = $divisor->absolute();
        $doubled = $absRemainder->multiply(2);
        $cmp = $doubled->compare($absDivisor);

        $negative = $dividend->isNegative() !== $divisor->isNegative();

        $shouldRoundAway = match ($mode) {
            PHP_ROUND_HALF_UP => $cmp >= 0,
            PHP_ROUND_HALF_DOWN => $cmp > 0,
            PHP_ROUND_HALF_EVEN => $cmp > 0 || ($cmp === 0 && !$quotient->modulus(2)->isZero()),
            PHP_ROUND_HALF_ODD => $cmp > 0 || ($cmp === 0 && $quotient->modulus(2)->isZero()),
            default => $cmp >= 0,
        };

        if ($shouldRoundAway) {
            return $negative ? $quotient->subtract(1) : $quotient->add(1);
        }

        return $quotient;
    }
}
