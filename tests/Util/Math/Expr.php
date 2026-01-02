<?php

/**
 * Test Expr arbitrary precision expression class
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Test;
use mini\Util\Math\Expr;
use mini\Util\Math\Decimal;
use mini\Util\Math\BigInt;

$test = new class extends Test {

    // ─────────────────────────────────────────────────────────────────────────
    // Direct tree construction
    // ─────────────────────────────────────────────────────────────────────────

    public function testDirectTreeDivision(): void
    {
        $expr = new Expr('/', Decimal::parse('10'), Decimal::parse('3'));
        $result = $expr->eval(maxScale: 4);
        $this->assertSame('3.3333', (string) $result);
    }

    public function testDirectTreeAddition(): void
    {
        $expr = new Expr('+', Decimal::parse('10'), Decimal::parse('5'));
        $result = $expr->eval();
        $this->assertSame('15', (string) $result);
    }

    public function testDirectTreeComplex(): void
    {
        // (10 + 5) * 2 = 30  →  Tree: Expr('*', Expr('+', 10, 5), 2)
        $expr = new Expr('*', new Expr('+', 10, 5), 2);
        $result = $expr->eval();
        $this->assertSame('30', (string) $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Expr::val() factory
    // ─────────────────────────────────────────────────────────────────────────

    public function testValWithInt(): void
    {
        $expr = Expr::val(42);
        $this->assertSame('42', (string) $expr->eval());
    }

    public function testValWithFloat(): void
    {
        $expr = Expr::val(3.14);
        $this->assertSame('3.14', (string) $expr->eval());
    }

    public function testValWithString(): void
    {
        $expr = Expr::val('123.456');
        $this->assertSame('123.456', (string) $expr->eval());
    }

    public function testValWithDecimal(): void
    {
        $d = Decimal::of('99.99', 2);
        $expr = Expr::val($d);
        $this->assertSame('99.99', (string) $expr->eval());
    }

    public function testValWithBigInt(): void
    {
        $b = BigInt::of('123456789012345678901234567890');
        $expr = Expr::val($b);
        $this->assertSame('123456789012345678901234567890', (string) $expr->eval());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Expr::parse() - string parsing
    // ─────────────────────────────────────────────────────────────────────────

    public function testParseSimpleAddition(): void
    {
        $expr = Expr::parse('10 + 5');
        $this->assertSame('15', (string) $expr->eval());
    }

    public function testParseSimpleDivision(): void
    {
        $expr = Expr::parse('10 / 3');
        $this->assertSame('3.3333', (string) $expr->eval(maxScale: 4));
    }

    public function testParsePrecedence(): void
    {
        // 10 + 5 * 2 = 20 (not 30)
        $expr = Expr::parse('10 + 5 * 2');
        $this->assertSame('20', (string) $expr->eval());
    }

    public function testParseParentheses(): void
    {
        // (10 + 5) * 2 = 30
        $expr = Expr::parse('(10 + 5) * 2');
        $this->assertSame('30', (string) $expr->eval());
    }

    public function testParseNestedParentheses(): void
    {
        // ((1 + 2) * (3 + 4)) = 21
        $expr = Expr::parse('((1 + 2) * (3 + 4))');
        $this->assertSame('21', (string) $expr->eval());
    }

    public function testParsePower(): void
    {
        $expr = Expr::parse('2 ** 10');
        $this->assertSame('1024', (string) $expr->eval());
    }

    public function testParsePowerRightAssociative(): void
    {
        // 2 ** 3 ** 2 = 2 ** 9 = 512 (right associative)
        $expr = Expr::parse('2 ** 3 ** 2');
        $this->assertSame('512', (string) $expr->eval());
    }

    public function testParseModulus(): void
    {
        $expr = Expr::parse('10 % 3');
        $this->assertSame('1', (string) $expr->eval());
    }

    public function testParseUnaryMinus(): void
    {
        $expr = Expr::parse('-5 + 10');
        $this->assertSame('5', (string) $expr->eval());
    }

    public function testParseUnaryMinusInParens(): void
    {
        $expr = Expr::parse('10 * (-3)');
        $this->assertSame('-30', (string) $expr->eval());
    }

    public function testParseUnaryMinusAfterOperator(): void
    {
        // 1 * -2 should parse as 1 * (neg 2) = -2
        $this->assertSame('-2', (string) Expr::parse('1 * -2')->eval());
        $this->assertTrue(Expr::parse('10 / -2')->eval()->equals('-5'));
        $this->assertSame('8', (string) Expr::parse('10 + -2')->eval());
        $this->assertSame('12', (string) Expr::parse('10 - -2')->eval());
    }

    public function testParseUnaryMinusWithParenthesizedExpr(): void
    {
        // -(1 + 2) = -3
        $this->assertSame('-3', (string) Expr::parse('-(1 + 2)')->eval());
        // 5 * -(2 + 3) = -25
        $this->assertSame('-25', (string) Expr::parse('5 * -(2 + 3)')->eval());
    }

    public function testParseDoubleNegation(): void
    {
        // -(-3) = 3
        $this->assertSame('3', (string) Expr::parse('-(-3)')->eval());
        // --3 = 3 (two unary minuses)
        $this->assertSame('3', (string) Expr::parse('--3')->eval());
    }

    public function testParsePowerWithNegativeExponent(): void
    {
        // 2 ** -1 = 0.5
        $result = Expr::parse('2 ** -1')->eval();
        $this->assertTrue($result->equals('0.5'));
        // 4 ** -2 = 0.0625
        $result = Expr::parse('4 ** -2')->eval();
        $this->assertTrue($result->equals('0.0625'));
    }

    public function testParseUnaryMinusPrecedenceWithPower(): void
    {
        // -2 ** 2 = -(2 ** 2) = -4 (matches Python/math convention)
        $this->assertSame('-4', (string) Expr::parse('-2 ** 2')->eval());
        // (-2) ** 2 = 4 (explicit grouping)
        $this->assertSame('4', (string) Expr::parse('(-2) ** 2')->eval());
    }

    public function testParseDecimalNumbers(): void
    {
        $expr = Expr::parse('1.5 + 2.5');
        $this->assertSame('4', (string) $expr);
    }

    public function testParseLargeNumbers(): void
    {
        $expr = Expr::parse('100000000000000000000000000000 / 3');
        $result = $expr->eval(maxScale: 0);
        $this->assertSame('33333333333333333333333333333', (string) $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lazy arithmetic methods
    // ─────────────────────────────────────────────────────────────────────────

    public function testLazyAdd(): void
    {
        $expr = Expr::val(10)->add(5);
        $this->assertSame('15', (string) $expr->eval());
    }

    public function testLazySubtract(): void
    {
        $expr = Expr::val(10)->subtract(3);
        $this->assertSame('7', (string) $expr->eval());
    }

    public function testLazyMultiply(): void
    {
        $expr = Expr::val(10)->multiply(3);
        $this->assertSame('30', (string) $expr->eval());
    }

    public function testLazyDivide(): void
    {
        $expr = Expr::val(10)->divide(3);
        $this->assertSame('3.3333', (string) $expr->eval(maxScale: 4));
    }

    public function testLazyModulus(): void
    {
        $expr = Expr::val(10)->modulus(3);
        $this->assertSame('1', (string) $expr->eval());
    }

    public function testLazyPow(): void
    {
        $expr = Expr::val(2)->pow(10);
        $this->assertSame('1024', (string) $expr->eval());
    }

    public function testLazyChaining(): void
    {
        // (10 / 3 + 1) * 2
        $expr = Expr::val(10)->divide(3)->add(1)->multiply(2);
        $result = $expr->eval(maxScale: 4);
        $this->assertSame('8.6666', (string) $result);
    }

    public function testLazyNegate(): void
    {
        $expr = Expr::val(5)->negate();
        $this->assertSame('-5', (string) $expr->eval());
    }

    public function testLazyAbsolute(): void
    {
        $expr = Expr::val(-5)->absolute();
        $this->assertSame('5', (string) $expr->eval());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Combining expressions
    // ─────────────────────────────────────────────────────────────────────────

    public function testCombineExpressions(): void
    {
        $a = Expr::val(10)->add(5);      // 15
        $b = Expr::val(3)->multiply(2);   // 6
        $c = $a->multiply($b);            // 15 * 6 = 90
        $this->assertSame('90', (string) $c->eval());
    }

    public function testExprWithDecimal(): void
    {
        $d = Decimal::of('2.5', 1);
        $expr = Expr::val(10)->multiply($d);
        $this->assertSame('25', (string) $expr);
    }

    public function testExprWithBigInt(): void
    {
        $b = BigInt::of(1000);
        $expr = Expr::val(2)->multiply($b);
        $this->assertSame('2000', (string) $expr->eval());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NumberInterface implementation
    // ─────────────────────────────────────────────────────────────────────────

    public function testScaleReturnsNull(): void
    {
        $expr = Expr::val(10);
        $this->assertNull($expr->scale());
    }

    public function testToStringEvaluates(): void
    {
        $expr = Expr::parse('10 / 4');
        $this->assertSame('2.5', (string) $expr);
    }

    public function testCompare(): void
    {
        $a = Expr::val(10)->divide(3);  // 3.333...
        $this->assertSame(1, $a->compare(3));
        $this->assertSame(-1, $a->compare(4));
    }

    public function testEquals(): void
    {
        $a = Expr::parse('6 / 2');
        $this->assertTrue($a->equals(3));
        $this->assertFalse($a->equals(4));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IteratorAggregate - RPN iteration
    // ─────────────────────────────────────────────────────────────────────────

    public function testIteratorSimpleBinaryOp(): void
    {
        $expr = new Expr('+', 1, 2);
        $rpn = iterator_to_array($expr, false);
        $this->assertSame([1, 2, '+'], $rpn);
    }

    public function testIteratorNestedExpr(): void
    {
        // 1 + (2 - 3) → RPN: 1 2 3 - +
        $expr = new Expr('+', 1, new Expr('-', 2, 3));
        $rpn = iterator_to_array($expr, false);
        $this->assertSame([1, 2, 3, '-', '+'], $rpn);
    }

    public function testIteratorComplexExpr(): void
    {
        // (1 + 2) * (3 - 4) → RPN: 1 2 + 3 4 - *
        $expr = new Expr('*', new Expr('+', 1, 2), new Expr('-', 3, 4));
        $rpn = iterator_to_array($expr, false);
        $this->assertSame([1, 2, '+', 3, 4, '-', '*'], $rpn);
    }

    public function testIteratorUnaryNegate(): void
    {
        // -5 → RPN: 5 neg
        $expr = new Expr('neg', 5);
        $rpn = iterator_to_array($expr, false);
        $this->assertSame([5, 'neg'], $rpn);
    }

    public function testIteratorValUsesPos(): void
    {
        // val() wraps in unary plus (identity) for fluent API
        $expr = Expr::val(42);
        $rpn = iterator_to_array($expr, false);
        $this->assertSame([42, 'pos'], $rpn);
    }

    public function testIteratorParsedExpr(): void
    {
        // (10 + 5) * 2 → RPN: 10 5 + 2 *
        $expr = Expr::parse('(10 + 5) * 2');
        $rpn = array_map(fn($v) => $v instanceof Decimal ? (string) $v : $v, iterator_to_array($expr, false));
        $this->assertSame(['10', '5', '+', '2', '*'], $rpn);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────────────────

    public function testDivisionByZeroThrows(): void
    {
        $expr = Expr::parse('10 / 0');
        $this->assertThrows(
            fn() => $expr->eval(),
            \DivisionByZeroError::class
        );
    }

    public function testEmptyParentheses(): void
    {
        $expr = Expr::parse('(((10)))');
        $this->assertSame('10', (string) $expr->eval());
    }

    public function testWhitespaceHandling(): void
    {
        $expr = Expr::parse('  10   +   5  ');
        $this->assertSame('15', (string) $expr->eval());
    }

    public function testPrecisionPreserved(): void
    {
        // Test that arbitrary precision is maintained
        $expr = Expr::parse('1 / 7');
        $result = $expr->eval(maxScale: 50);
        $this->assertSame(
            '0.14285714285714285714285714285714285714285714285714',
            (string) $result
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Python semantics compatibility
    // ─────────────────────────────────────────────────────────────────────────

    public function testPythonUnaryMinusBindsLooserThanPower(): void
    {
        // Python: -2**2 == -(2**2) == -4
        $this->assertSame('-4', (string) Expr::parse('-2 ** 2')->eval());
        $this->assertSame('-4', (string) Expr::parse('-(2 ** 2)')->eval());

        // And parentheses override:
        $this->assertSame('4', (string) Expr::parse('(-2) ** 2')->eval());
    }

    public function testPythonPowerBindsTighterOnRightHandNegativeExponent(): void
    {
        // Python: 2**-2 == 2**(-2) == 0.25
        $r = Expr::parse('2 ** -2')->eval(maxScale: 10);
        $this->assertTrue($r->equals('0.25'));

        // Python: (-2)**-2 == 0.25 as well
        $r = Expr::parse('(-2) ** -2')->eval(maxScale: 10);
        $this->assertTrue($r->equals('0.25'));

        // Python: -(2**-2) == -0.25
        $r = Expr::parse('-(2 ** -2)')->eval(maxScale: 10);
        $this->assertTrue($r->equals('-0.25'));
    }

    public function testPythonDoubleMinusAndChainAfterBinaryOp(): void
    {
        // Python: 10 - -2 == 12
        $this->assertSame('12', (string) Expr::parse('10 - -2')->eval());

        // Python: 10 + - -2 == 12  (because - -2 == 2)
        $this->assertSame('12', (string) Expr::parse('10 + - -2')->eval());

        // Python: 1 * - -2 == 2
        $this->assertSame('2', (string) Expr::parse('1 * - -2')->eval());
    }

    public function testPythonUnaryPlusNoOp(): void
    {
        // Python accepts unary plus as no-op: +2 == 2
        // If your parser doesn't implement unary '+', this test should fail until you add it.
        $this->assertSame('2', (string) Expr::parse('+2')->eval());
        $this->assertSame('2', (string) Expr::parse('(+2)')->eval());
        $this->assertSame('3', (string) Expr::parse('1 + +2')->eval());
        $this->assertSame('2', (string) Expr::parse('1 * +2')->eval());
    }

    public function testPythonPowerRightAssociativeWithUnaryMinus(): void
    {
        // Python: -2**3**2 == -(2**(3**2)) == -512
        $this->assertSame('-512', (string) Expr::parse('-2 ** 3 ** 2')->eval());

        // Parentheses override:
        // (-2)**(3**2) == (-2)**9 == -512
        $this->assertSame('-512', (string) Expr::parse('(-2) ** (3 ** 2)')->eval());

        // (-(2**3))**2 == (-8)**2 == 64
        $this->assertSame('64', (string) Expr::parse('(-(2 ** 3)) ** 2')->eval());
    }
    
    public function testPythonExponentOfNegativeExponentExpression(): void
    {
        // Python: 2**-(1+1) == 2**-2 == 0.25
        $r = Expr::parse('2 ** -(1 + 1)')->eval(maxScale: 10);
        $this->assertTrue($r->equals('0.25'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Function parsing: sqrt, exp, ln, abs
    // ─────────────────────────────────────────────────────────────────────────

    public function testParseSqrt(): void
    {
        $this->assertTrue(Expr::parse('sqrt(4)')->eval()->equals('2'));
        $this->assertTrue(Expr::parse('sqrt(9)')->eval()->equals('3'));
        $this->assertTrue(Expr::parse('sqrt(0)')->eval()->equals('0'));
    }

    public function testParseSqrtComposed(): void
    {
        // sqrt(2) ** 2 ≈ 2
        $r = Expr::parse('sqrt(2) ** 2')->eval(maxScale: 10);
        $this->assertTrue($r->greaterThan('1.999999'));
        $this->assertTrue($r->lessThan('2.000001'));

        // 2 * sqrt(2)
        $r = Expr::parse('2 * sqrt(2)')->eval(maxScale: 10);
        $this->assertTrue($r->greaterThan('2.828'));
        $this->assertTrue($r->lessThan('2.829'));
    }

    public function testParseExp(): void
    {
        // exp(0) = 1
        $this->assertTrue(Expr::parse('exp(0)')->eval()->equals('1'));

        // exp(1) ≈ e ≈ 2.71828...
        $r = Expr::parse('exp(1)')->eval(maxScale: 10);
        $this->assertTrue($r->greaterThan('2.71828'));
        $this->assertTrue($r->lessThan('2.71829'));
    }

    public function testParseLn(): void
    {
        // ln(1) = 0
        $this->assertTrue(Expr::parse('ln(1)')->eval()->equals('0'));

        // ln(e) ≈ 1
        $r = Expr::parse('ln(exp(1))')->eval(maxScale: 10);
        $this->assertTrue($r->greaterThan('0.9999'));
        $this->assertTrue($r->lessThan('1.0001'));
    }

    public function testParseAbs(): void
    {
        $this->assertSame('5', (string) Expr::parse('abs(5)')->eval());
        $this->assertSame('5', (string) Expr::parse('abs(-5)')->eval());
        $this->assertSame('0', (string) Expr::parse('abs(0)')->eval());
    }

    public function testParseFunctionNested(): void
    {
        // sqrt(abs(-4)) = 2
        $this->assertTrue(Expr::parse('sqrt(abs(-4))')->eval()->equals('2'));

        // exp(ln(5)) ≈ 5
        $r = Expr::parse('exp(ln(5))')->eval(maxScale: 10);
        $this->assertTrue($r->greaterThan('4.999'));
        $this->assertTrue($r->lessThan('5.001'));
    }

    public function testParseFunctionInExpression(): void
    {
        // 1 + sqrt(4) = 3
        $this->assertTrue(Expr::parse('1 + sqrt(4)')->eval()->equals('3'));

        // sqrt(4) + sqrt(9) = 5
        $this->assertTrue(Expr::parse('sqrt(4) + sqrt(9)')->eval()->equals('5'));

        // sqrt(4) * 3 = 6
        $this->assertTrue(Expr::parse('sqrt(4) * 3')->eval()->equals('6'));
    }

    public function testLazyTranscendental(): void
    {
        // Test fluent API
        $this->assertTrue(Expr::val(4)->sqrt()->eval()->equals('2'));
        $this->assertTrue(Expr::val(0)->exp()->eval()->equals('1'));
        $this->assertTrue(Expr::val(1)->ln()->eval()->equals('0'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ergonomic API: normalize parses string expressions
    // ─────────────────────────────────────────────────────────────────────────

    public function testNormalizeFractions(): void
    {
        // pow('1/2') = sqrt
        $this->assertTrue(Expr::val(4)->pow('1/2')->eval()->equals('2'));
        $this->assertTrue(Expr::val(9)->pow('1/2')->eval()->equals('3'));

        // pow('1/3') = cube root
        $r = Expr::val(8)->pow('1/3')->eval(10);
        $this->assertTrue($r->greaterThan('1.999'));
        $this->assertTrue($r->lessThan('2.001'));
    }

    public function testNormalizeExpressions(): void
    {
        // add('1/2') parses the fraction
        $this->assertTrue(Expr::val(1)->add('1/2')->eval()->equals('1.5'));

        // multiply with expression
        $this->assertTrue(Expr::val(2)->multiply('3+1')->eval()->equals('8'));

        // divide with expression
        $this->assertTrue(Expr::val(6)->divide('1+2')->eval()->equals('2'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Structural optimization verification
    // These tests verify that optimizations fire based on structure, not numeric coincidence
    // ─────────────────────────────────────────────────────────────────────────

    public function testSqrtOptimizationExact(): void
    {
        // x ** (1/2) uses Newton-Raphson sqrt, giving exact results for perfect squares
        // (Note: sqrt(2) ** 2 would need simplification rule (x**a)**b → x**(a*b), not implemented yet)
        $this->assertSame('2.00000000000000000000', (string) Expr::val(4)->pow('1/2')->eval(maxScale: 20));
        $this->assertSame('3.00000000000000000000', (string) Expr::val(9)->pow('1/2')->eval(maxScale: 20));
        $this->assertSame('10.00000000000000000000', (string) Expr::val(100)->pow('1/2')->eval(maxScale: 20));
    }

    public function testReducibleRatioSqrt(): void
    {
        // 4 ** (2/4) should use sqrt optimization (2/4 reduces to 1/2)
        $result = Expr::val(4)->pow('2/4')->eval(maxScale: 20);
        $this->assertSame('2.00000000000000000000', (string) $result);

        // 9 ** (50/100) = sqrt(9) = 3
        $result = Expr::val(9)->pow('50/100')->eval(maxScale: 20);
        $this->assertSame('3.00000000000000000000', (string) $result);
    }

    public function testIsRatioReducible(): void
    {
        // Direct ratio matching tests
        $half = Expr::parse('1/2');
        $twoFourths = Expr::parse('2/4');
        $fiftyPercent = Expr::parse('50/100');

        $this->assertTrue($half->isRatio(1, 2));
        $this->assertTrue($twoFourths->isRatio(1, 2));
        $this->assertTrue($fiftyPercent->isRatio(1, 2));

        $this->assertFalse($half->isRatio(1, 3));
        $this->assertFalse($twoFourths->isRatio(2, 3));
    }

    public function testScalarEqualsDecimal(): void
    {
        // Decimal('1.0') should match integer 1 for pattern matching
        $expr = new Expr('/', Decimal::of('1.0', 1), Decimal::of('2.0', 1));
        $this->assertTrue($expr->isRatio(1, 2));

        // Decimal with fractional part should NOT match integer ratios
        $expr2 = new Expr('/', Decimal::of('1.5', 1), 2);
        $this->assertFalse($expr2->isRatio(1, 2));
        // 1.5 is not an integer, so scalarToBigInt returns null, so isRatio returns false
        $this->assertFalse($expr2->isRatio(3, 4));
    }

    public function testIsRatioWithLargeNumbers(): void
    {
        // Test that isRatio doesn't overflow with large integers
        // If we used int multiplication, this would overflow PHP_INT_MAX
        // The values are chosen so cross-multiplication would overflow:
        // 10^18 * 2 = 2*10^18 > PHP_INT_MAX ≈ 9.2*10^18
        $large = '1000000000000000000'; // 10^18
        $expr = new Expr('/', $large, '2000000000000000000'); // (10^18) / (2*10^18) = 1/2

        // This would fail with int overflow, but works with BigInt
        $this->assertTrue($expr->isRatio(1, 2));
        $this->assertFalse($expr->isRatio(1, 3));

        // Even larger numbers
        $huge = '99999999999999999999999999999'; // 29 digits
        $expr2 = new Expr('/', $huge, $huge);
        $this->assertTrue($expr2->isRatio(1, 1));
        $this->assertTrue($expr2->isRatio(7, 7)); // 7n/7n = 1/1 = n/n

        // Pi approximation with large numerator/denominator
        $expr3 = new Expr('/', '245850922', '78256779');
        $this->assertTrue($expr3->isRatio(245850922, 78256779));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // asRational - rational number extraction
    // ─────────────────────────────────────────────────────────────────────────

    public function testAsRationalExact(): void
    {
        // Already a ratio
        $half = Expr::parse('1/2');
        $ratio = $half->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(1, 2));

        // Decimal 0.5 → 1/2
        $decimal = Expr::val(Decimal::of('0.5', 1));
        $ratio = $decimal->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(1, 2));

        // Decimal 0.25 → 1/4
        $decimal = Expr::val(Decimal::of('0.25', 2));
        $ratio = $decimal->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(1, 4));

        // Float 0.5 → 1/2 (dyadic)
        $float = Expr::val(0.5);
        $ratio = $float->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(1, 2));

        // Integer → n/1
        $int = Expr::val(5);
        $ratio = $int->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(5, 1));
    }

    public function testAsRationalApproximation(): void
    {
        // π is stored as exact ratio, so asRational returns that
        $pi = Expr::pi();
        $ratio = $pi->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(245850922, 78256779));

        // 0.333 is exactly 333/1000 (not 1/3)
        $third = Expr::val(Decimal::of('0.333', 3));
        $ratio = $third->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(333, 1000));

        // 0.5 reduces to 1/2
        $half = Expr::val(Decimal::of('0.5', 1));
        $ratio = $half->asRational();
        $this->assertNotNull($ratio);
        $this->assertTrue($ratio->isRatio(1, 2));

        // Test continued fraction approximation on computed irrational
        // sqrt(2) ≈ 1.414... - not expressible exactly, needs approximation
        $sqrt2 = Expr::sqrt2();
        $ratio = $sqrt2->asRational(3);
        $this->assertNotNull($ratio);
        // Should be close to 1.414, within 0.001 (precision 3)
        $approx = $ratio->eval(10);
        $diff = $approx->subtract('1.41421356')->absolute();
        $this->assertTrue($diff->lessThan('0.001'));
    }

    public function testNumericSqrtOptimization(): void
    {
        // Numeric 0.5 should now hit sqrt optimization via asRatio
        $result = Expr::val(4)->pow(0.5)->eval(maxScale: 20);
        $this->assertSame('2.00000000000000000000', (string) $result);

        // Decimal 0.5 should also work
        $result = Expr::val(9)->pow(Decimal::of('0.5', 1))->eval(maxScale: 20);
        $this->assertSame('3.00000000000000000000', (string) $result);
    }

};

exit($test->run());
