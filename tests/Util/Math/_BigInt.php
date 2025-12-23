<?php

namespace tests\Util\Math;

use mini\Test;
use mini\Util\Math\Int\IntValue;

/**
 * Implementation-agnostic tests for IntValue implementations
 *
 * Extend this class and implement createValue() to test a specific implementation.
 * Override canRun() and skipReason() to skip if required extension is missing.
 */
abstract class _BigInt extends Test
{
    /**
     * Create an IntValue instance for testing
     */
    abstract protected function createValue(string|int $value): IntValue;

    // ─────────────────────────────────────────────────────────────────────────
    // Factory tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testOfWithInteger(): void
    {
        $value = $this->createValue(42);
        $this->assertSame('42', (string) $value);
    }

    public function testOfWithString(): void
    {
        $value = $this->createValue('123456789');
        $this->assertSame('123456789', (string) $value);
    }

    public function testOfWithNegative(): void
    {
        $value = $this->createValue(-42);
        $this->assertSame('-42', (string) $value);
    }

    public function testOfWithLeadingZeros(): void
    {
        $value = $this->createValue('00042');
        $this->assertSame('42', (string) $value);
    }

    public function testOfWithPlusSign(): void
    {
        $value = $this->createValue('+42');
        $this->assertSame('42', (string) $value);
    }

    public function testOfWithNegativeZero(): void
    {
        $value = $this->createValue('-0');
        $this->assertSame('0', (string) $value);
    }

    public function testZero(): void
    {
        $class = get_class($this->createValue(0));
        $value = $class::zero();
        $this->assertSame('0', (string) $value);
    }

    public function testOne(): void
    {
        $class = get_class($this->createValue(0));
        $value = $class::one();
        $this->assertSame('1', (string) $value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Addition
    // ─────────────────────────────────────────────────────────────────────────

    public function testAddPositives(): void
    {
        $a = $this->createValue(123);
        $this->assertSame('579', (string) $a->add(456));
    }

    public function testAddNegatives(): void
    {
        $a = $this->createValue(-10);
        $this->assertSame('-30', (string) $a->add(-20));
    }

    public function testAddMixed(): void
    {
        $a = $this->createValue(10);
        $this->assertSame('5', (string) $a->add(-5));
    }

    public function testAddWithZero(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('42', (string) $a->add(0));
    }

    public function testAddLargeNumbers(): void
    {
        $a = $this->createValue('100000000000000000000');
        $this->assertSame('100000000000000000001', (string) $a->add(1));
    }

    public function testAddWithIntValue(): void
    {
        $a = $this->createValue(10);
        $b = $this->createValue(20);
        $this->assertSame('30', (string) $a->add($b));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Subtraction
    // ─────────────────────────────────────────────────────────────────────────

    public function testSubtractPositives(): void
    {
        $a = $this->createValue(100);
        $this->assertSame('58', (string) $a->subtract(42));
    }

    public function testSubtractToNegative(): void
    {
        $a = $this->createValue(10);
        $this->assertSame('-10', (string) $a->subtract(20));
    }

    public function testSubtractNegative(): void
    {
        $a = $this->createValue(10);
        $this->assertSame('15', (string) $a->subtract(-5));
    }

    public function testSubtractToZero(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('0', (string) $a->subtract(42));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Multiplication
    // ─────────────────────────────────────────────────────────────────────────

    public function testMultiplyPositives(): void
    {
        $a = $this->createValue(6);
        $this->assertSame('42', (string) $a->multiply(7));
    }

    public function testMultiplyWithNegative(): void
    {
        $a = $this->createValue(6);
        $this->assertSame('-42', (string) $a->multiply(-7));
    }

    public function testMultiplyNegatives(): void
    {
        $a = $this->createValue(-6);
        $this->assertSame('42', (string) $a->multiply(-7));
    }

    public function testMultiplyByZero(): void
    {
        $a = $this->createValue(999);
        $this->assertSame('0', (string) $a->multiply(0));
    }

    public function testMultiplyByOne(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('42', (string) $a->multiply(1));
    }

    public function testMultiplyLargeNumbers(): void
    {
        $a = $this->createValue('123456789012345678901234567890');
        $this->assertSame('246913578024691357802469135780', (string) $a->multiply(2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Division
    // ─────────────────────────────────────────────────────────────────────────

    public function testDivideExact(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('6', (string) $a->divide(7));
    }

    public function testDivideTruncates(): void
    {
        $a = $this->createValue(10);
        $this->assertSame('3', (string) $a->divide(3));
    }

    public function testDivideNegative(): void
    {
        $a = $this->createValue(-42);
        $this->assertSame('-6', (string) $a->divide(7));
    }

    public function testDivideByNegative(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('-6', (string) $a->divide(-7));
    }

    public function testDivideBothNegative(): void
    {
        $a = $this->createValue(-42);
        $this->assertSame('6', (string) $a->divide(-7));
    }

    public function testDivideByZeroThrows(): void
    {
        $a = $this->createValue(42);
        $this->assertThrows(fn() => $a->divide(0), \DivisionByZeroError::class);
    }

    public function testDivideLargeBySmall(): void
    {
        $a = $this->createValue('123456789012345678901234567890');
        $this->assertSame('61728394506172839450617283945', (string) $a->divide(2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Modulus
    // ─────────────────────────────────────────────────────────────────────────

    public function testModulus(): void
    {
        $a = $this->createValue(10);
        $this->assertSame('1', (string) $a->modulus(3));
    }

    public function testModulusExact(): void
    {
        $a = $this->createValue(9);
        $this->assertSame('0', (string) $a->modulus(3));
    }

    public function testModulusNegative(): void
    {
        $a = $this->createValue(-10);
        $this->assertSame('-1', (string) $a->modulus(3));
    }

    public function testModulusByZeroThrows(): void
    {
        $a = $this->createValue(42);
        $this->assertThrows(fn() => $a->modulus(0), \DivisionByZeroError::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Power
    // ─────────────────────────────────────────────────────────────────────────

    public function testPowerOfZero(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('1', (string) $a->power(0));
    }

    public function testPowerOfOne(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('42', (string) $a->power(1));
    }

    public function testPowerOfTwo(): void
    {
        $a = $this->createValue(5);
        $this->assertSame('25', (string) $a->power(2));
    }

    public function testPowerOfTen(): void
    {
        $a = $this->createValue(2);
        $this->assertSame('1024', (string) $a->power(10));
    }

    public function testNegativeExponentThrows(): void
    {
        $a = $this->createValue(2);
        $this->assertThrows(fn() => $a->power(-1), \InvalidArgumentException::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Negate and Absolute
    // ─────────────────────────────────────────────────────────────────────────

    public function testNegatePositive(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('-42', (string) $a->negate());
    }

    public function testNegateNegative(): void
    {
        $a = $this->createValue(-42);
        $this->assertSame('42', (string) $a->negate());
    }

    public function testNegateZero(): void
    {
        $a = $this->createValue(0);
        $this->assertSame('0', (string) $a->negate());
    }

    public function testAbsolutePositive(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('42', (string) $a->absolute());
    }

    public function testAbsoluteNegative(): void
    {
        $a = $this->createValue(-42);
        $this->assertSame('42', (string) $a->absolute());
    }

    public function testAbsoluteZero(): void
    {
        $a = $this->createValue(0);
        $this->assertSame('0', (string) $a->absolute());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison
    // ─────────────────────────────────────────────────────────────────────────

    public function testCompareEqual(): void
    {
        $a = $this->createValue(42);
        $this->assertSame(0, $a->compare(42));
    }

    public function testCompareLess(): void
    {
        $a = $this->createValue(10);
        $this->assertSame(-1, $a->compare(20));
    }

    public function testCompareGreater(): void
    {
        $a = $this->createValue(20);
        $this->assertSame(1, $a->compare(10));
    }

    public function testCompareNegatives(): void
    {
        $a = $this->createValue(-5);
        $this->assertSame(-1, $a->compare(-3)); // -5 < -3
    }

    public function testEquals(): void
    {
        $a = $this->createValue(42);
        $this->assertTrue($a->equals(42));
        $this->assertFalse($a->equals(43));
    }

    public function testLessThan(): void
    {
        $a = $this->createValue(10);
        $this->assertTrue($a->lessThan(20));
        $this->assertFalse($a->lessThan(10));
        $this->assertFalse($a->lessThan(5));
    }

    public function testGreaterThan(): void
    {
        $a = $this->createValue(20);
        $this->assertTrue($a->greaterThan(10));
        $this->assertFalse($a->greaterThan(20));
        $this->assertFalse($a->greaterThan(30));
    }

    public function testLessThanOrEqual(): void
    {
        $a = $this->createValue(10);
        $this->assertTrue($a->lessThanOrEqual(20));
        $this->assertTrue($a->lessThanOrEqual(10));
        $this->assertFalse($a->lessThanOrEqual(5));
    }

    public function testGreaterThanOrEqual(): void
    {
        $a = $this->createValue(20);
        $this->assertTrue($a->greaterThanOrEqual(10));
        $this->assertTrue($a->greaterThanOrEqual(20));
        $this->assertFalse($a->greaterThanOrEqual(30));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Predicates
    // ─────────────────────────────────────────────────────────────────────────

    public function testIsZero(): void
    {
        $this->assertTrue($this->createValue(0)->isZero());
        $this->assertFalse($this->createValue(1)->isZero());
        $this->assertFalse($this->createValue(-1)->isZero());
    }

    public function testIsPositive(): void
    {
        $this->assertTrue($this->createValue(1)->isPositive());
        $this->assertTrue($this->createValue(999)->isPositive());
        $this->assertFalse($this->createValue(0)->isPositive());
        $this->assertFalse($this->createValue(-1)->isPositive());
    }

    public function testIsNegative(): void
    {
        $this->assertTrue($this->createValue(-1)->isNegative());
        $this->assertTrue($this->createValue(-999)->isNegative());
        $this->assertFalse($this->createValue(0)->isNegative());
        $this->assertFalse($this->createValue(1)->isNegative());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion
    // ─────────────────────────────────────────────────────────────────────────

    public function testToInt(): void
    {
        $a = $this->createValue(42);
        $this->assertSame(42, $a->toInt());
    }

    public function testToIntNegative(): void
    {
        $a = $this->createValue(-42);
        $this->assertSame(-42, $a->toInt());
    }

    public function testToIntOverflowThrows(): void
    {
        $a = $this->createValue('999999999999999999999999999999');
        $this->assertThrows(fn() => $a->toInt(), \OverflowException::class);
    }

    public function testToString(): void
    {
        $a = $this->createValue(42);
        $this->assertSame('42', (string) $a);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Immutability
    // ─────────────────────────────────────────────────────────────────────────

    public function testAddDoesNotMutate(): void
    {
        $a = $this->createValue(10);
        $b = $a->add(5);
        $this->assertSame('10', (string) $a);
        $this->assertSame('15', (string) $b);
    }

    public function testNegateDoesNotMutate(): void
    {
        $a = $this->createValue(10);
        $b = $a->negate();
        $this->assertSame('10', (string) $a);
        $this->assertSame('-10', (string) $b);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────────────────

    public function testVeryLargeNumber(): void
    {
        $large = str_repeat('9', 100);
        $a = $this->createValue($large);
        $this->assertSame($large, (string) $a);
    }

    public function testVeryLargeAddition(): void
    {
        $a = $this->createValue(str_repeat('9', 50));
        $b = $a->add(1);
        $expected = '1' . str_repeat('0', 50);
        $this->assertSame($expected, (string) $b);
    }

    public function testChainedOperations(): void
    {
        $result = $this->createValue(10)
            ->add(5)
            ->multiply(2)
            ->subtract(10)
            ->divide(2);
        $this->assertSame('10', (string) $result);
    }

    public function testDivisionModulusIdentity(): void
    {
        // Identity: a == (a / b) * b + (a % b)
        $cases = [
            ['123456789012345678901234567890', '98765432109876543210'],
            ['100', '7'],
            ['-100', '7'],
            ['100', '-7'],
            ['-100', '-7'],
        ];

        foreach ($cases as [$aStr, $bStr]) {
            $a = $this->createValue($aStr);
            $b = $this->createValue($bStr);
            $reconstructed = $a->divide($b)->multiply($b)->add($a->modulus($b));
            $this->assertSame((string) $a, (string) $reconstructed, "Identity failed for $aStr / $bStr");
        }
    }

    public function testOfWithPlusAndLeadingZeros(): void
    {
        $value = $this->createValue('+00042');
        $this->assertSame('42', (string) $value);
    }

    public function testOfWithNegativeLeadingZeros(): void
    {
        $value = $this->createValue('-00042');
        $this->assertSame('-42', (string) $value);
    }

    public function testDivideByZeroLikeStringsThrow(): void
    {
        $a = $this->createValue(42);

        // These were a real footgun in the earlier implementation
        foreach (['0', '00', '+0', '-0', '0000', '+000', '-000'] as $z) {
            $this->assertThrows(fn() => $a->divide($z), \DivisionByZeroError::class);
        }
    }

    public function testModulusByZeroLikeStringsThrow(): void
    {
        $a = $this->createValue(42);

        foreach (['0', '00', '+0', '-0', '0000', '+000', '-000'] as $z) {
            $this->assertThrows(fn() => $a->modulus($z), \DivisionByZeroError::class);
        }
    }
    public function testDivideTruncatesTowardZeroNegative(): void
    {
        $a = $this->createValue(-10);
        $this->assertSame('-3', (string) $a->divide(3));  // PHP intdiv truncates toward 0
    }

    public function testDivideTruncatesTowardZeroMixedSigns(): void
    {
        $this->assertSame('-3', (string) $this->createValue(10)->divide(-3));
        $this->assertSame('3',  (string) $this->createValue(-10)->divide(-3));
    }

    public function testModulusFollowsPhpSignRules(): void
    {
        // In PHP, remainder has the sign of the dividend
        $this->assertSame('1',  (string) $this->createValue(10)->modulus(3));
        $this->assertSame('-1', (string) $this->createValue(-10)->modulus(3));
        $this->assertSame('1',  (string) $this->createValue(10)->modulus(-3));
        $this->assertSame('-1', (string) $this->createValue(-10)->modulus(-3));
    }

    public function testModulusMagnitudeLessThanDivisorMagnitude(): void
    {
        $a = $this->createValue('123456789012345678901234567890');
        $r = $a->modulus('97');
        $this->assertTrue($r->greaterThanOrEqual(0));
        $this->assertTrue($r->lessThan(97));
    }
    public function testMultiplyCarriesAcrossManyDigits(): void
    {
        // 99..99 * 99..99 has lots of cross-carries
        $a = $this->createValue(str_repeat('9', 40));
        $b = $this->createValue(str_repeat('9', 40));

        // Pattern: 99^2 = 9801, 999^2 = 998001, 9999^2 = 99980001
        // So 9{n}^2 = 9{n-1}8 followed by 0{n-1}1
        $n = 40;
        $expected = str_repeat('9', $n - 1) . '8' . str_repeat('0', $n - 1) . '1';

        $this->assertSame($expected, (string) $a->multiply($b));
    }

    public function testMultiplyWithManyZeros(): void
    {
        $a = $this->createValue('1000000000000000000000000000000'); // 10^30
        $b = $this->createValue('1000000000000000000000000000000'); // 10^30
        $this->assertSame('1' . str_repeat('0', 60), (string) $a->multiply($b));
    }
    public function testDivideLargeByLargeNotExact(): void
    {
        // 30-digit / 20-digit ≈ 10^10
        $a = $this->createValue('123456789012345678901234567890');
        $q = $a->divide('98765432109876543210');
        $this->assertSame('1249999988', (string) $q);
    }

    public function testDivideLargeByLargeExact(): void
    {
        $a = $this->createValue('98765432109876543210');
        $this->assertSame('1', (string) $a->divide('98765432109876543210'));
    }

    public function testDivideByOneAndMinusOne(): void
    {
        $a = $this->createValue('123456789012345678901234567890');
        $this->assertSame((string)$a, (string) $a->divide(1));
        $this->assertSame('-123456789012345678901234567890', (string) $a->divide(-1));
    }
    public function testToIntAtPhpIntMax(): void
    {
        $max = (string) PHP_INT_MAX;
        $a = $this->createValue($max);
        $this->assertSame(PHP_INT_MAX, $a->toInt());
    }

    public function testToIntAbovePhpIntMaxThrows(): void
    {
        $above = $this->createValue((string) PHP_INT_MAX)->add(1);
        $this->assertThrows(fn() => $above->toInt(), \OverflowException::class);
    }

    public function testToIntAtPhpIntMin(): void
    {
        $min = (string) PHP_INT_MIN;
        $a = $this->createValue($min);
        $this->assertSame(PHP_INT_MIN, $a->toInt());
    }

    public function testToIntBelowPhpIntMinThrows(): void
    {
        $below = $this->createValue((string) PHP_INT_MIN)->subtract(1);
        $this->assertThrows(fn() => $below->toInt(), \OverflowException::class);
    }
}
