<?php

namespace mini\Util\Math;

/**
 * Expression tree with lazy evaluation
 *
 * Stores expressions as a tree structure for optimization opportunities.
 * Arithmetic operations build up the expression tree lazily; call eval() to compute.
 *
 * Precision model:
 * - Basic arithmetic (+, -, *, /, %) uses arbitrary precision via Decimal/BigInt
 * - Integer powers use exact binary exponentiation
 * - Transcendental functions (exp, ln) and fractional powers use high-precision
 *   iterative algorithms (Taylor series, Newton-Raphson) with configurable scale
 *
 * Usage:
 *   $expr = new Expr('/', 10, 3);
 *   $result = $expr->eval(maxScale: 10);  // Decimal: 3.3333333333
 *
 *   $expr = Expr::parse('(10 + 5) * 2');
 *   $result = $expr->eval();  // Decimal: 30
 *
 *   $expr = Expr::val(10)->divide(3)->add(1);
 *   $result = $expr->eval(maxScale: 4);  // Decimal: 4.3333
 */
final class Expr implements NumberInterface, \IteratorAggregate
{
    /**
     * Create an expression node
     *
     * Operations:
     * - Binary: '+', '-', '*', '/', '%', '**'
     * - Unary: 'neg', 'pos', 'abs', 'exp', 'ln'
     *
     * Note: sqrt(x) is represented as x**(1/2) for easier simplification.
     * Parser emits Decimal leaves directly; fluent API accepts scalars
     * and normalizes during construction or eval.
     *
     * @param string $op The operation
     * @param NumberInterface|self|int|float|string $operand First operand
     * @param NumberInterface|self|int|float|string|null $other Second operand for binary ops
     */
    public function __construct(
        public readonly string $op,
        public readonly NumberInterface|self|int|float|string $operand,
        public readonly NumberInterface|self|int|float|string|null $other = null,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Factory methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create expression from a single value (fluent API entry point)
     *
     * Returns the value wrapped in unary plus (identity operation) so you can
     * chain methods: Expr::val(5)->add(3)->multiply(2)
     */
    public static function val(NumberInterface|self|int|float|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        // Use 'pos' (unary plus) as identity wrapper for fluent API
        return new self('pos', $value);
    }

    /**
     * e (Euler's number) - base of natural logarithm
     *
     * Symbolic: exp(1), computed to arbitrary precision at eval()
     */
    public static function e(): self
    {
        return new self('exp', 1);
    }

    /**
     * √2 (Pythagoras' constant)
     *
     * Symbolic: 2^(1/2), computed to arbitrary precision at eval()
     */
    public static function sqrt2(): self
    {
        return self::val(2)->pow('1/2');
    }

    /**
     * √3
     */
    public static function sqrt3(): self
    {
        return self::val(3)->pow('1/2');
    }

    /**
     * √5
     */
    public static function sqrt5(): self
    {
        return self::val(5)->pow('1/2');
    }

    /**
     * ln(2) - natural logarithm of 2
     *
     * Symbolic: ln(2), computed to arbitrary precision at eval()
     */
    public static function ln2(): self
    {
        return new self('ln', 2);
    }

    /**
     * ln(10) - natural logarithm of 10
     */
    public static function ln10(): self
    {
        return new self('ln', 10);
    }

    /**
     * φ (phi) - golden ratio (1 + √5) / 2
     *
     * Symbolic expression, computed to arbitrary precision at eval()
     */
    public static function phi(): self
    {
        return self::val(1)->add(self::sqrt5())->divide(2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transcendental constants (rational approximations)
    // These require specialized algorithms for arbitrary precision computation.
    // TODO: Implement Chudnovsky for pi, etc.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * π (pi) - ratio of circumference to diameter
     *
     * Rational approximation, ~15 digits accuracy.
     * TODO: Implement arbitrary precision via Chudnovsky algorithm
     */
    public static function pi(): self
    {
        return new self('/', '245850922', '78256779');
    }

    /**
     * π/2
     */
    public static function piHalf(): self
    {
        return self::pi()->divide(2);
    }

    /**
     * π/4
     */
    public static function piQuarter(): self
    {
        return self::pi()->divide(4);
    }

    /**
     * γ (gamma) - Euler-Mascheroni constant
     *
     * Rational approximation, ~11 digits accuracy.
     */
    public static function gamma(): self
    {
        return new self('/', '323007', '559595');
    }

    /**
     * G - Catalan's constant
     *
     * Rational approximation, ~14 digits accuracy.
     */
    public static function catalan(): self
    {
        return new self('/', '15280193', '16682060');
    }

    /**
     * ζ(2) = π²/6 - Basel problem
     */
    public static function zeta2(): self
    {
        return self::pi()->pow(2)->divide(6);
    }

    /**
     * Parse an infix expression string
     *
     * Supports: +, -, *, /, %, ** (power), parentheses, unary minus
     *
     * Examples:
     *   Expr::parse('10 / 3')
     *   Expr::parse('(1 + 2) * 3')
     *   Expr::parse('2 ** 10')
     */
    public static function parse(string $input): self
    {
        $parser = new ExprParser($input);
        return $parser->parse();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Evaluation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Evaluate the expression to a Decimal
     *
     * Uses recursive evaluation to preserve tree structure for pattern matching.
     *
     * @param int $maxScale Maximum decimal places for division operations
     */
    public function eval(int $maxScale = 20): Decimal
    {
        return $this->evalNode($this->op, $this->operand, $this->other, $maxScale);
    }

    /**
     * Recursively evaluate an expression node
     *
     * Using recursive evaluation (instead of RPN) preserves tree structure,
     * allowing structural pattern matching for optimizations like x^(1/2) → sqrt(x).
     */
    private function evalNode(
        string $op,
        NumberInterface|self|int|float|string $left,
        NumberInterface|self|int|float|string|null $right,
        int $maxScale
    ): Decimal {
        // Evaluate operands recursively
        $a = $left instanceof self
            ? $left->eval($maxScale)
            : $this->toDecimal($left, $maxScale);

        // Unary operators
        switch ($op) {
            case 'neg':
                return $a->negate();
            case 'pos':
                return $a;
            case 'abs':
                return $a->absolute();
            case 'exp':
                return $a->exp($maxScale);
            case 'ln':
                return $a->ln($maxScale);
        }

        // Binary operators - evaluate right operand
        $b = $right instanceof self
            ? $right->eval($maxScale)
            : $this->toDecimal($right, $maxScale);

        switch ($op) {
            case '+':
                return $a->add($b);
            case '-':
                return $a->subtract($b);
            case '*':
                return $a->multiply($b);
            case '/':
                return $a->divide($b, $maxScale);
            case '%':
                return $a->modulus($b);
            case '**':
                return $this->evalPower($a, $right, $b, $maxScale);
            default:
                throw new \RuntimeException("Unknown operator: $op");
        }
    }

    /**
     * Evaluate power with structural pattern matching
     *
     * Detects patterns like x^(1/2) and uses optimized algorithms.
     */
    private function evalPower(
        Decimal $base,
        NumberInterface|self|int|float|string|null $exponentExpr,
        Decimal $exponentValue,
        int $maxScale
    ): Decimal {
        // Check for integer exponent - use fast binary exponentiation
        $intPart = $exponentValue->rescale(0);
        if ($exponentValue->equals($intPart)) {
            return $base->pow($exponentValue, null);
        }

        // Try to get exponent as exact ratio
        $ratio = null;
        if ($exponentExpr instanceof self) {
            $ratio = $exponentExpr->asRational();
        } elseif ($exponentExpr !== null) {
            // Wrap scalar and try
            $wrapped = new self('pos', $exponentExpr);
            $ratio = $wrapped->asRational();
        }

        // Check for x^(1/2) = sqrt(x)
        if ($ratio !== null && $ratio->isRatio(1, 2)) {
            return $base->sqrt($maxScale);
        }

        // TODO: Could extend to x^(1/n) = nth root, x^(p/q) = (x^p)^(1/q)

        // Fallback: exp(y * ln(x))
        return $base->pow($exponentValue, $maxScale);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arithmetic (lazy - returns new Expr)
    // ─────────────────────────────────────────────────────────────────────────

    public function add(NumberInterface|self|int|float|string $other): self
    {
        $other = self::normalize($other);
        // Optimization: x + 0 = x
        if ($this->isZero($other)) {
            return $this;
        }
        return new self('+', $this, $other);
    }

    public function subtract(NumberInterface|self|int|float|string $other): self
    {
        $other = self::normalize($other);
        // Optimization: x - 0 = x
        if ($this->isZero($other)) {
            return $this;
        }
        return new self('-', $this, $other);
    }

    public function multiply(NumberInterface|self|int|float|string $other): self
    {
        $other = self::normalize($other);
        // Optimization: x * 1 = x
        if ($this->isOne($other)) {
            return $this;
        }
        // Optimization: x * 0 = 0
        if ($this->isZero($other)) {
            return self::val(0);
        }
        return new self('*', $this, $other);
    }

    public function divide(NumberInterface|self|int|float|string $other): self
    {
        $other = self::normalize($other);
        // Optimization: x / 1 = x
        if ($this->isOne($other)) {
            return $this;
        }
        return new self('/', $this, $other);
    }

    public function modulus(NumberInterface|self|int|float|string $other): self
    {
        return new self('%', $this, self::normalize($other));
    }

    public function pow(NumberInterface|self|int|float|string $exponent): self
    {
        $exponent = self::normalize($exponent);
        // Optimization: x ** 1 = x
        if ($this->isOne($exponent)) {
            return $this;
        }
        // Optimization: x ** 0 = 1
        if ($this->isZero($exponent)) {
            return self::val(1);
        }
        return new self('**', $this, $exponent);
    }

    /**
     * Negate: -x
     */
    public function negate(): self
    {
        // Optimization: --x = x
        if ($this->op === 'neg') {
            // Unwrap double negation - operand could be Expr or Decimal
            if ($this->operand instanceof self) {
                return $this->operand;
            }
            return new self('pos', $this->operand);
        }
        return new self('neg', $this);
    }

    /**
     * Absolute value
     */
    public function absolute(): self
    {
        return new self('abs', $this);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transcendental functions (lazy - returns new Expr)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Square root: √x = x^(1/2)
     *
     * Represented as power for simplification (e.g., sqrt(x)**2 = x)
     */
    public function sqrt(): self
    {
        // Optimization: √0 = 0, √1 = 1
        if ($this->isZero($this) || $this->isOne($this)) {
            return $this;
        }
        return $this->pow('1/2');
    }

    /**
     * Natural exponential: e^x
     */
    public function exp(): self
    {
        // Optimization: e^0 = 1
        if ($this->isZero($this)) {
            return self::val(1);
        }
        return new self('exp', $this);
    }

    /**
     * Natural logarithm: ln(x)
     */
    public function ln(): self
    {
        // Optimization: ln(1) = 0
        if ($this->isOne($this)) {
            return self::val(0);
        }
        return new self('ln', $this);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NumberInterface implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scale is unknown until evaluated
     */
    public function scale(): ?int
    {
        return null;
    }

    /**
     * Evaluates and returns normalized string representation
     */
    public function __toString(): string
    {
        $result = (string) $this->eval();

        // Trim trailing zeros after decimal point
        if (str_contains($result, '.')) {
            $result = rtrim(rtrim($result, '0'), '.');
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison (evaluates both sides)
    // ─────────────────────────────────────────────────────────────────────────

    public function compare(NumberInterface|self|int|float|string $other, int $maxScale = 20): int
    {
        $a = $this->eval($maxScale);
        $b = $this->toDecimal($other, $maxScale);
        return $a->compare($b);
    }

    public function equals(NumberInterface|self|int|float|string $other, int $maxScale = 20): bool
    {
        return $this->compare($other, $maxScale) === 0;
    }

    public function lessThan(NumberInterface|self|int|float|string $other, int $maxScale = 20): bool
    {
        return $this->compare($other, $maxScale) < 0;
    }

    public function greaterThan(NumberInterface|self|int|float|string $other, int $maxScale = 20): bool
    {
        return $this->compare($other, $maxScale) > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IteratorAggregate - yields RPN tokens
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Iterate in RPN (postfix) order
     *
     * Expr('+', 1, 2) yields: 1, 2, '+'
     * Expr('+', 1, Expr('-', 2, 3)) yields: 1, 2, 3, '-', '+'
     *
     * @return \Generator<int, NumberInterface|int|float|string>
     */
    public function getIterator(): \Generator
    {
        // First operand (recursive for nested Expr, direct for values)
        if ($this->operand instanceof self) {
            yield from $this->operand;
        } else {
            yield $this->operand;
        }

        // Second operand for binary ops
        if ($this->other !== null) {
            if ($this->other instanceof self) {
                yield from $this->other;
            } else {
                yield $this->other;
            }
        }

        // Finally the operator
        yield $this->op;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Optimization helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function isZero(NumberInterface|self|int|float|string $value): bool
    {
        if ($value instanceof self) {
            return false; // Can't know without evaluation
        }
        if ($value instanceof NumberInterface) {
            return (string) $value === '0';
        }
        return $value === 0 || $value === 0.0 || $value === '0';
    }

    private function isOne(NumberInterface|self|int|float|string $value): bool
    {
        if ($value instanceof self) {
            return false; // Can't know without evaluation
        }
        if ($value instanceof NumberInterface) {
            return (string) $value === '1';
        }
        return $value === 1 || $value === 1.0 || $value === '1';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pattern matching helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if scalar value equals expected integer
     *
     * Handles Decimal('1.0') == 1, BigInt, int, float, string.
     */
    private static function scalarEquals(mixed $value, int $expected): bool
    {
        if ($value instanceof self) {
            return false;
        }
        if ($value instanceof Decimal) {
            // Check if value is an integer equal to expected
            // e.g., Decimal('1.0') equals 1, Decimal('1.5') does not equal 1
            $rescaled = $value->rescale(0);
            return $value->equals($rescaled) && (string) $rescaled === (string) $expected;
        }
        if ($value instanceof BigInt) {
            return (string) $value === (string) $expected;
        }
        if (is_int($value)) {
            return $value === $expected;
        }
        if (is_float($value)) {
            return $value === (float) $expected && floor($value) === $value;
        }
        // String - check if it's an integer string
        if (is_numeric($value)) {
            return (int) $value == $expected && (string) (int) $value === $value;
        }
        return false;
    }

    /**
     * Structural pattern matching on expression tree
     *
     * Checks if this Expr matches the given operator and operand patterns.
     * Use null to match any value, or a specific value to match exactly.
     *
     * Examples:
     *   $expr->matches('/')           // is this a division?
     *   $expr->matches('/', 1, 2)     // is this 1/2?
     *   $expr->matches('/', null, 2)  // is this ?/2 (anything divided by 2)?
     *   $expr->matches('**', null, fn($e) => $e->matches('/', 1, 2))  // x^(1/2)?
     */
    public function matches(
        string $op,
        int|callable|null $left = null,
        int|callable|null $right = null
    ): bool {
        if ($this->op !== $op) {
            return false;
        }

        // Check left operand
        if ($left !== null) {
            if (is_callable($left)) {
                if (!$left($this->operand)) {
                    return false;
                }
            } elseif (!self::scalarEquals($this->operand, $left)) {
                return false;
            }
        }

        // Check right operand
        if ($right !== null) {
            if (is_callable($right)) {
                if (!$right($this->other)) {
                    return false;
                }
            } elseif (!self::scalarEquals($this->other, $right)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if this Expr represents the ratio p/q (handles reducible ratios)
     *
     * Uses BigInt for cross-multiplication to avoid integer overflow.
     *
     * Examples:
     *   Expr('/', 1, 2)->isRatio(1, 2)  // true
     *   Expr('/', 2, 4)->isRatio(1, 2)  // true (2/4 reduces to 1/2)
     *   Expr('/', 50, 100)->isRatio(1, 2)  // true
     */
    public function isRatio(int $p, int $q): bool
    {
        if ($this->op !== '/') {
            return false;
        }
        if ($q === 0) {
            return false;
        }

        // Extract numerator and denominator as BigInt for safe multiplication
        $num = self::scalarToBigInt($this->operand);
        $den = self::scalarToBigInt($this->other);

        if ($num === null || $den === null || $den->isZero()) {
            return false;
        }

        // Cross-multiply using BigInt: num/den == p/q iff num*q == den*p
        $left = $num->multiply($q);
        $right = $den->multiply($p);
        return $left->equals($right);
    }

    /**
     * Extract integer value from scalar as BigInt, or null if not an integer
     *
     * Safe for arbitrarily large integers (no overflow).
     */
    private static function scalarToBigInt(mixed $value): ?BigInt
    {
        if ($value instanceof self) {
            return null;
        }
        if ($value instanceof Decimal) {
            // Check if it's an integer (scale 0 or all zeros after decimal)
            $rescaled = $value->rescale(0);
            if (!$value->equals($rescaled)) {
                return null; // Has fractional part
            }
            return $rescaled->unscaledValue();
        }
        if ($value instanceof BigInt) {
            return $value;
        }
        if (is_int($value)) {
            return BigInt::of($value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            // Float that represents an integer
            return BigInt::of((string) (int) $value);
        }
        if (is_string($value)) {
            // Check if it's an integer string (no decimal point, no exponent)
            if (preg_match('/^-?\d+$/', $value)) {
                return BigInt::of($value);
            }
        }
        return null;
    }

    /**
     * Get rational number representation of this expression
     *
     * Attempts to express this value as Expr('/', p, q) where p, q are integers.
     * A rational number is the quotient of two integers, unlike a general ratio
     * which could involve irrationals (e.g., √2/√3).
     *
     * @param int|null $precision If null, returns only exact rationals.
     *                            If set, allows approximation within 10^-precision.
     * @return self|null Expr('/', p, q) or null if not representable
     */
    public function asRational(?int $precision = null): ?self
    {
        // Step 1: Try exact arithmetic
        $exact = $this->tryExactRatio();
        if ($exact !== null) {
            return $exact;
        }

        // Step 2: No precision = no approximation
        if ($precision === null) {
            return null;
        }

        // Step 3: Approximate via continued fractions
        $value = $this->eval($precision + 5); // Extra precision for algorithm
        return self::continuedFraction($value, $precision);
    }

    /**
     * Try to get exact rational representation without approximation
     */
    private function tryExactRatio(): ?self
    {
        // Already a ratio of integers
        if ($this->op === '/') {
            $p = self::scalarToBigInt($this->operand);
            $q = self::scalarToBigInt($this->other);
            if ($p !== null && $q !== null && !$q->isZero()) {
                return $this;
            }
        }

        // Unary plus wrapping a value
        if ($this->op === 'pos') {
            return self::valueToRatio($this->operand);
        }

        // Integer literal with unary op
        $intVal = self::scalarToBigInt($this->operand);
        if ($intVal !== null && $this->other === null && in_array($this->op, ['pos', 'neg'], true)) {
            $val = $this->op === 'neg' ? $intVal->negate() : $intVal;
            return new self('/', $val, 1);
        }

        return null;
    }

    /**
     * Convert a scalar value to rational Expr
     */
    private static function valueToRatio(mixed $value): ?self
    {
        if ($value instanceof Decimal) {
            return self::decimalToRatio($value);
        }
        if ($value instanceof BigInt) {
            return new self('/', $value, 1);
        }
        if (is_int($value)) {
            return new self('/', $value, 1);
        }
        if (is_float($value)) {
            return self::floatToRatio($value);
        }
        if (is_string($value) && is_numeric($value)) {
            // Parse as Decimal for exact representation
            return self::decimalToRatio(Decimal::parse($value));
        }
        return null;
    }

    /**
     * Convert Decimal to exact rational (always possible)
     *
     * Decimal stores value as unscaled/10^scale, so we use unscaledValue()
     * directly and reduce by GCD.
     */
    private static function decimalToRatio(Decimal $value): self
    {
        $scale = $value->scale();
        $unscaled = $value->unscaledValue();

        if ($scale === 0) {
            // Integer - no denominator needed
            return new self('/', $unscaled, 1);
        }

        // Decimal = unscaled / 10^scale
        // Build denominator: 10^scale
        $den = BigInt::of('1' . str_repeat('0', $scale));

        // Reduce by GCD
        $gcd = self::bigIntGcd($unscaled->absolute(), $den);

        $reducedNum = $unscaled->divide($gcd);
        $reducedDen = $den->divide($gcd);

        return new self('/', $reducedNum, $reducedDen);
    }

    /**
     * Convert float to exact rational (only for dyadic rationals)
     *
     * IEEE 754 floats are exactly representable only when they're
     * of the form p/2^k (dyadic rationals).
     */
    private static function floatToRatio(float $value): ?self
    {
        if (!is_finite($value)) {
            return null;
        }
        if ($value == 0.0) {
            return new self('/', 0, 1);
        }

        // Check if it's a "nice" fraction by testing round-trip
        // This catches 0.5, 0.25, 0.125, etc.
        $negative = $value < 0;
        $absValue = abs($value);

        // Try small denominators first (most common case)
        foreach ([1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024] as $den) {
            $num = $absValue * $den;
            if ($num == floor($num) && $num <= PHP_INT_MAX) {
                $intNum = (int) $num;
                if ($negative) {
                    $intNum = -$intNum;
                }
                // Reduce
                $gcd = self::intGcd(abs($intNum), $den);
                return new self('/', $intNum / $gcd, $den / $gcd);
            }
        }

        return null; // Not a nice dyadic rational
    }

    /**
     * Find best rational approximation using continued fractions
     */
    private static function continuedFraction(Decimal $value, int $precision): self
    {
        $epsilon = Decimal::of(1, 0)->divide(
            Decimal::of(10, 0)->pow($precision, 0),
            $precision
        );

        $negative = $value->isNegative();
        $x = $negative ? $value->negate() : $value;

        // Continued fraction convergents
        $p0 = BigInt::of(0);
        $p1 = BigInt::of(1);
        $q0 = BigInt::of(1);
        $q1 = BigInt::of(0);

        for ($i = 0; $i < 50; $i++) {
            // a = floor(x)
            $a = BigInt::of((string) $x->rescale(0));

            // p2 = a * p1 + p0, q2 = a * q1 + q0
            $p2 = $a->multiply($p1)->add($p0);
            $q2 = $a->multiply($q1)->add($q0);

            // Check if p2/q2 is close enough
            $approx = Decimal::of((string) $p2, 0)->divide(
                Decimal::of((string) $q2, 0),
                $precision + 2
            );
            $absVal = $negative ? $value->negate() : $value;
            $diff = $absVal->subtract($approx)->absolute();

            if ($diff->lessThan($epsilon) || $diff->isZero()) {
                $numStr = $negative ? '-' . (string) $p2 : (string) $p2;
                return new self('/', $numStr, (string) $q2);
            }

            // Prepare for next iteration
            $remainder = $x->subtract(Decimal::of((string) $a, 0));
            if ($remainder->isZero()) {
                $numStr = $negative ? '-' . (string) $p2 : (string) $p2;
                return new self('/', $numStr, (string) $q2);
            }

            $p0 = $p1;
            $p1 = $p2;
            $q0 = $q1;
            $q1 = $q2;

            $x = Decimal::of(1, 0)->divide($remainder, $precision + 10);
        }

        // Return best approximation found
        $numStr = $negative ? '-' . (string) $p1 : (string) $p1;
        return new self('/', $numStr, (string) $q1);
    }

    /**
     * GCD for BigInt
     */
    private static function bigIntGcd(BigInt $a, BigInt $b): BigInt
    {
        while (!$b->isZero()) {
            $temp = $b;
            $b = $a->modulus($b);
            $a = $temp;
        }
        return $a;
    }

    /**
     * GCD for int
     */
    private static function intGcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }
        return $a;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalize input to Expr or NumberInterface
     *
     * Allows ergonomic API: $val->pow('1/2') instead of $val->pow(new Expr('/', 1, 2))
     *
     * - Expr: returned as-is
     * - NumberInterface: returned as-is
     * - int/float: converted to Decimal
     * - string with operators: parsed as expression ('1/2', '2+3', etc.)
     * - numeric string: converted to Decimal
     */
    private static function normalize(NumberInterface|self|int|float|string $value): self|NumberInterface
    {
        if ($value instanceof self || $value instanceof NumberInterface) {
            return $value;
        }
        if (is_int($value)) {
            return Decimal::of($value, 0);
        }
        if (is_float($value)) {
            return Decimal::parse((string) $value);
        }
        // String - check if it looks like an expression (contains operators)
        if (preg_match('/[+\-*\/%()]/', $value) && !preg_match('/^[+-]?\d*\.?\d+$/', $value)) {
            return self::parse($value);
        }
        return Decimal::parse($value);
    }

    /**
     * Convert value to Decimal for evaluation
     */
    private function toDecimal(
        NumberInterface|self|int|float|string|null $value,
        int $maxScale
    ): Decimal {
        if ($value === null) {
            throw new \RuntimeException('Missing operand');
        }
        if ($value instanceof self) {
            return $value->eval($maxScale);
        }
        if ($value instanceof Decimal) {
            return $value;
        }
        if ($value instanceof BigInt) {
            return Decimal::of((string) $value, 0);
        }
        if (is_int($value)) {
            return Decimal::of($value, 0);
        }
        // float or string
        return Decimal::parse((string) $value);
    }
}

/**
 * Infix to expression tree parser using shunting-yard algorithm
 *
 * Supports:
 * - Binary operators: +, -, *, /, %, **
 * - Unary operators: -x, +x
 * - Functions: sqrt(x), exp(x), ln(x), abs(x)
 * - Parentheses
 * - Decimal numbers
 *
 * @internal
 */
final class ExprParser
{
    private string $input;
    private int $pos = 0;
    private int $len;

    // Precedence levels (higher = binds tighter)
    // Note: unary minus has same precedence as ** but is right-associative,
    // so -2**2 parses as -(2**2) = -4, matching Python/math convention.
    private const PRECEDENCE = [
        '+' => 1,
        '-' => 1,
        '*' => 2,
        '/' => 2,
        '%' => 2,
        '**' => 3,
        'neg' => 3,  // Unary minus
        'pos' => 3,  // Unary plus
        'sqrt' => 4, // Functions have highest precedence
        'exp' => 4,
        'ln' => 4,
        'abs' => 4,
    ];

    // Right-associative operators
    private const RIGHT_ASSOC = ['**', 'neg', 'pos'];

    // Unary operators (take 1 operand)
    private const UNARY = ['neg', 'pos', 'sqrt', 'exp', 'ln', 'abs'];

    // Function names
    private const FUNCTIONS = ['sqrt', 'exp', 'ln', 'abs'];

    public function __construct(string $input)
    {
        $this->input = $input;
        $this->len = strlen($input);
    }

    public function parse(): Expr
    {
        $output = [];
        $operators = [];
        $expectOperand = true; // Track whether we expect an operand next

        while ($this->pos < $this->len) {
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                break;
            }

            $char = $this->input[$this->pos];

            if ($char === '(') {
                $operators[] = '(';
                $this->pos++;
                $expectOperand = true;
            } elseif ($char === ')') {
                while ($operators && end($operators) !== '(') {
                    $this->applyOperator(array_pop($operators), $output);
                }
                if (!$operators) {
                    throw new \InvalidArgumentException('Mismatched parentheses');
                }
                array_pop($operators); // Remove '('
                $this->pos++;
                $expectOperand = false; // After ), we expect an operator
            } elseif ($this->isOperatorStart($char)) {
                $op = $this->readOperator();

                // Handle unary operators: when we expect an operand
                if ($expectOperand) {
                    if ($op === '-') {
                        $op = 'neg';
                    } elseif ($op === '+') {
                        $op = 'pos';
                    }
                }

                // Push operator with proper precedence handling
                while ($operators && end($operators) !== '(' && $this->shouldPopOperator(end($operators), $op)) {
                    $this->applyOperator(array_pop($operators), $output);
                }
                $operators[] = $op;

                // After unary operator, we still expect an operand
                // After binary operator, we expect an operand
                $expectOperand = true;
            } elseif (ctype_alpha($char)) {
                // Try to read a function name
                $func = $this->readFunction();
                if ($func !== null) {
                    // Push function onto operator stack
                    $operators[] = $func;
                    // Expect '(' next
                    $this->skipWhitespace();
                    if ($this->pos >= $this->len || $this->input[$this->pos] !== '(') {
                        throw new \InvalidArgumentException("Expected '(' after function '$func'");
                    }
                    $operators[] = '(';
                    $this->pos++;
                    $expectOperand = true;
                } else {
                    throw new \InvalidArgumentException("Unknown identifier at position {$this->pos}");
                }
            } else {
                // Push Decimal directly as leaf node (no Expr wrapper)
                $output[] = $this->readNumber();
                $expectOperand = false; // After a number, we expect an operator
            }
        }

        while ($operators) {
            $op = array_pop($operators);
            if ($op === '(') {
                throw new \InvalidArgumentException('Mismatched parentheses');
            }
            $this->applyOperator($op, $output);
        }

        if (count($output) !== 1) {
            throw new \RuntimeException('Invalid expression');
        }

        $result = $output[0];

        // If single value, wrap for fluent API compatibility
        if (!$result instanceof Expr) {
            return new Expr('pos', $result);
        }

        return $result;
    }

    private function applyOperator(string $op, array &$output): void
    {
        if (in_array($op, self::UNARY, true)) {
            // Unary operator: pop 1 operand
            if (count($output) < 1) {
                throw new \RuntimeException("Not enough operands for '$op'");
            }
            $operand = array_pop($output);

            // Convert sqrt(x) to x ** (1/2) for easier simplification
            if ($op === 'sqrt') {
                $output[] = new Expr('**', $operand, new Expr('/', 1, 2));
            } else {
                $output[] = new Expr($op, $operand);
            }
        } else {
            // Binary operator: pop 2 operands
            if (count($output) < 2) {
                throw new \RuntimeException("Not enough operands for '$op'");
            }
            $right = array_pop($output);
            $left = array_pop($output);
            $output[] = new Expr($op, $left, $right);
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && ctype_space($this->input[$this->pos])) {
            $this->pos++;
        }
    }

    private function isOperatorStart(string $char): bool
    {
        return in_array($char, ['+', '-', '*', '/', '%'], true);
    }

    private function readOperator(): string
    {
        $char = $this->input[$this->pos];
        $this->pos++;

        // Check for **
        if ($char === '*' && $this->pos < $this->len && $this->input[$this->pos] === '*') {
            $this->pos++;
            return '**';
        }

        return $char;
    }

    /**
     * Try to read a function name
     *
     * @return string|null Function name if recognized, null otherwise
     */
    private function readFunction(): ?string
    {
        $start = $this->pos;

        // Read identifier
        while ($this->pos < $this->len && ctype_alpha($this->input[$this->pos])) {
            $this->pos++;
        }

        $name = substr($this->input, $start, $this->pos - $start);

        if (in_array($name, self::FUNCTIONS, true)) {
            return $name;
        }

        // Not a function, reset position
        $this->pos = $start;
        return null;
    }

    private function shouldPopOperator(string $top, string $current): bool
    {
        if (!isset(self::PRECEDENCE[$top])) {
            return false;
        }

        $topPrec = self::PRECEDENCE[$top];
        $currPrec = self::PRECEDENCE[$current];

        if (in_array($current, self::RIGHT_ASSOC, true)) {
            return $topPrec > $currPrec;
        }

        return $topPrec >= $currPrec;
    }

    private function readNumber(): Decimal
    {
        $this->skipWhitespace();
        $start = $this->pos;

        // Integer part
        while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
            $this->pos++;
        }

        // Decimal part
        if ($this->pos < $this->len && $this->input[$this->pos] === '.') {
            $this->pos++;
            while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
                $this->pos++;
            }
        }

        if ($this->pos === $start) {
            $remaining = substr($this->input, $this->pos, 20);
            throw new \InvalidArgumentException("Expected number at position {$this->pos}: '$remaining...'");
        }

        $numStr = substr($this->input, $start, $this->pos - $start);
        return Decimal::parse($numStr);
    }
}
