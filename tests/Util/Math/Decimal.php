<?php

/**
 * Test Decimal arbitrary precision decimal class
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use mini\Test;
use mini\Util\Math\Decimal;

$test = new class extends Test {

    public function testOfWithInteger(): void
    {
        $d = Decimal::of(123, 2);
        $this->assertSame('123.00', (string) $d);
        $this->assertSame(2, $d->scale());
    }

    public function testOfWithStringDecimal(): void
    {
        $d = Decimal::of('123.45', 2);
        $this->assertSame('123.45', (string) $d);
    }

    public function testOfWithStringInteger(): void
    {
        $d = Decimal::of('100', 3);
        $this->assertSame('100.000', (string) $d);
    }

    public function testOfScaleZero(): void
    {
        $d = Decimal::of('42', 0);
        $this->assertSame('42', (string) $d);
    }

    public function testOfWithMorePrecisionThanScaleRounds(): void
    {
        $d = Decimal::of('123.456', 2);
        $this->assertSame('123.46', (string) $d); // rounded half up
    }

    public function testOfWithLessPrecisionPads(): void
    {
        $d = Decimal::of('123.4', 4);
        $this->assertSame('123.4000', (string) $d);
    }

    public function testOfNegative(): void
    {
        $d = Decimal::of('-123.45', 2);
        $this->assertSame('-123.45', (string) $d);
    }

    public function testZero(): void
    {
        $d = Decimal::zero(2);
        $this->assertSame('0.00', (string) $d);
        $this->assertTrue($d->isZero());
    }

    public function testOne(): void
    {
        $d = Decimal::one(2);
        $this->assertSame('1.00', (string) $d);
    }

    public function testAddSameScale(): void
    {
        $a = Decimal::of('10.50', 2);
        $b = Decimal::of('3.25', 2);
        $this->assertSame('13.75', (string) $a->add($b));
    }

    public function testAddDifferentScales(): void
    {
        $a = Decimal::of('10.5', 1);
        $b = Decimal::of('3.25', 2);
        $result = $a->add($b);
        $this->assertSame('13.75', (string) $result);
        $this->assertSame(2, $result->scale()); // max scale
    }

    public function testAddWithInt(): void
    {
        $a = Decimal::of('10.50', 2);
        $this->assertSame('15.50', (string) $a->add(5));
    }

    public function testAddWithString(): void
    {
        $a = Decimal::of('10.50', 2);
        $this->assertSame('13.75', (string) $a->add('3.25'));
    }

    public function testSubtract(): void
    {
        $a = Decimal::of('10.50', 2);
        $b = Decimal::of('3.25', 2);
        $this->assertSame('7.25', (string) $a->subtract($b));
    }

    public function testMultiplySameScale(): void
    {
        $a = Decimal::of('10.50', 2);
        $b = Decimal::of('2.00', 2);
        $result = $a->multiply($b);
        $this->assertSame('21.0000', (string) $result);
        $this->assertSame(4, $result->scale()); // 2 + 2
    }

    public function testMultiplyWithInt(): void
    {
        $a = Decimal::of('10.50', 2);
        $this->assertSame('21.00', (string) $a->multiply(2));
    }

    public function testDivideWithExplicitScale(): void
    {
        $a = Decimal::of('10', 0);
        $b = Decimal::of('3', 0);
        $result = $a->divide($b, 4);
        $this->assertSame('3.3333', (string) $result);
    }

    public function testDivideDefaultScale(): void
    {
        $a = Decimal::of('10.00', 2);
        $b = Decimal::of('4.00', 2);
        $result = $a->divide($b);
        // Default: max(2,2) + 6 = 8
        $this->assertSame(8, $result->scale());
        $this->assertSame('2.50000000', (string) $result);
    }

    public function testDivideByZeroThrows(): void
    {
        $a = Decimal::of('10', 2);
        $this->assertThrows(
            fn() => $a->divide(0),
            \DivisionByZeroError::class
        );
    }

    public function testModulus(): void
    {
        $a = Decimal::of('10.00', 2);
        $b = Decimal::of('3.00', 2);
        $this->assertSame('1.00', (string) $a->modulus($b));
    }

    public function testNegate(): void
    {
        $a = Decimal::of('10.50', 2);
        $this->assertSame('-10.50', (string) $a->negate());
        $this->assertSame('10.50', (string) $a->negate()->negate());
    }

    public function testAbsolute(): void
    {
        $a = Decimal::of('-10.50', 2);
        $this->assertSame('10.50', (string) $a->absolute());
    }

    public function testCompareSameScale(): void
    {
        $a = Decimal::of('10.50', 2);
        $b = Decimal::of('10.25', 2);
        $this->assertSame(1, $a->compare($b));
        $this->assertSame(-1, $b->compare($a));
        $this->assertSame(0, $a->compare('10.50'));
    }

    public function testCompareDifferentScales(): void
    {
        $a = Decimal::of('1.00', 2);
        $b = Decimal::of('1.0', 1);
        $this->assertSame(0, $a->compare($b)); // numerically equal
        $this->assertTrue($a->equals($b));
    }

    public function testComparisonMethods(): void
    {
        $a = Decimal::of('10', 2);
        $b = Decimal::of('5', 2);
        $this->assertTrue($a->greaterThan($b));
        $this->assertTrue($b->lessThan($a));
        $this->assertTrue($a->greaterThanOrEqual(10));
        $this->assertTrue($a->lessThanOrEqual(10));
    }

    public function testPredicates(): void
    {
        $this->assertTrue(Decimal::of('0', 2)->isZero());
        $this->assertTrue(Decimal::of('10', 2)->isPositive());
        $this->assertTrue(Decimal::of('-10', 2)->isNegative());
        $this->assertFalse(Decimal::of('0', 2)->isPositive());
        $this->assertFalse(Decimal::of('0', 2)->isNegative());
    }

    public function testRescaleUp(): void
    {
        $a = Decimal::of('10.50', 2);
        $b = $a->rescale(4);
        $this->assertSame('10.5000', (string) $b);
        $this->assertSame(4, $b->scale());
    }

    public function testRescaleDownWithRounding(): void
    {
        $a = Decimal::of('10.555', 3);
        $b = $a->rescale(2);
        $this->assertSame('10.56', (string) $b); // half up
    }

    public function testRescaleDownExact(): void
    {
        $a = Decimal::of('10.500', 3);
        $b = $a->rescale(2);
        $this->assertSame('10.50', (string) $b);
    }

    public function testToFloat(): void
    {
        $a = Decimal::of('123.45', 2);
        $this->assertSame(123.45, $a->toFloat());
    }

    public function testSerialization(): void
    {
        $a = Decimal::of('123.456789', 6);
        $serialized = serialize($a);
        $b = unserialize($serialized);
        $this->assertSame((string) $a, (string) $b);
        $this->assertSame($a->scale(), $b->scale());
    }

    public function testChaining(): void
    {
        $result = Decimal::of('10', 2)
            ->add('5')
            ->multiply(2)
            ->subtract('10')
            ->divide(2, 2);
        $this->assertSame('10.00', (string) $result);
    }

    public function testLargeNumbers(): void
    {
        $a = Decimal::of('999999999999999999999999.99', 2);
        $b = Decimal::of('0.01', 2);
        $result = $a->add($b);
        $this->assertSame('1000000000000000000000000.00', (string) $result);
    }

    public function testSmallDecimal(): void
    {
        $a = Decimal::of('0.001', 3);
        $b = Decimal::of('0.002', 3);
        $this->assertSame('0.003', (string) $a->add($b));
    }

    public function testNegativeScaleThrows(): void
    {
        $this->assertThrows(
            fn() => Decimal::of('10', -1),
            \InvalidArgumentException::class
        );
    }

    public function testDotForms(): void
    {
        $this->assertSame('1.00', (string) Decimal::of('1.', 2));
        $this->assertSame('0.50', (string) Decimal::of('.5', 2));
    }
    public function testPlusAndLeadingZeros(): void
    {
        $this->assertSame('123.45', (string) Decimal::of('+000123.45', 2));
    }
    public function testRoundingNegativeHalfUp(): void
    {
        $d = Decimal::of('-1.235', 2);
        $this->assertSame('-1.24', (string)$d); // away from zero
    }
    public function testRescaleNegativeHalfUp(): void
    {
        $d = Decimal::of('-10.555', 3)->rescale(2);
        $this->assertSame('-10.56', (string)$d);
    }
    public function testDivideRoundingAtScale(): void
    {
        $a = Decimal::of('1', 0);
        $b = Decimal::of('6', 0);
        $this->assertSame('0.17', (string)$a->divide($b, 2)); // 0.1666.. -> 0.17
    }
    public function testDivideNegative(): void
    {
        $a = Decimal::of('-10', 0);
        $b = Decimal::of('4', 0);
        $this->assertSame('-2.50', (string)$a->divide($b, 2));
    }
    public function testOneScaleZero(): void
    {
        $this->assertSame('1', (string) Decimal::one(0));
    }
    public function testSerializationType(): void
    {
        $a = Decimal::of('123.456789', 6);
        $b = unserialize(serialize($a));
        $this->assertTrue($b instanceof Decimal);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transcendental functions
    // ─────────────────────────────────────────────────────────────────────────

    public function testSqrtOfTwo(): void
    {
        $sqrt2 = Decimal::of(2, 0)->sqrt(15);
        // √2 ≈ 1.41421356237309...
        $this->assertTrue($sqrt2->greaterThan('1.414213562373'));
        $this->assertTrue($sqrt2->lessThan('1.414213562374'));
    }

    public function testSqrtOfPerfectSquare(): void
    {
        $result = Decimal::of(9, 0)->sqrt(10);
        $this->assertSame('3.0000000000', (string) $result);
    }

    public function testSqrtOfZero(): void
    {
        $result = Decimal::of(0, 0)->sqrt(10);
        $this->assertTrue($result->isZero());
    }

    public function testSqrtNegativeThrows(): void
    {
        $this->assertThrows(
            fn() => Decimal::of(-4, 0)->sqrt(),
            \InvalidArgumentException::class
        );
    }

    public function testExpOfZero(): void
    {
        $result = Decimal::of(0, 0)->exp(10);
        $this->assertSame('1.0000000000', (string) $result);
    }

    public function testExpOfOne(): void
    {
        $e = Decimal::of(1, 0)->exp(15);
        // e ≈ 2.71828182845904...
        $this->assertTrue($e->greaterThan('2.718281828459'));
        $this->assertTrue($e->lessThan('2.718281828460'));
    }

    public function testExpOfNegative(): void
    {
        $result = Decimal::of(-1, 0)->exp(10);
        // e^(-1) ≈ 0.3678794411...
        $this->assertTrue($result->greaterThan('0.36787944'));
        $this->assertTrue($result->lessThan('0.36787945'));
    }

    public function testLnOfOne(): void
    {
        $result = Decimal::of(1, 0)->ln(10);
        $this->assertTrue($result->isZero());
    }

    public function testLnOfE(): void
    {
        // Use a high-precision approximation of e
        $e = Decimal::of(1, 0)->exp(20);
        $lnE = $e->ln(10);
        // Should be very close to 1
        $this->assertTrue($lnE->greaterThan('0.9999999'));
        $this->assertTrue($lnE->lessThan('1.0000001'));
    }

    public function testLnOfTwo(): void
    {
        $ln2 = Decimal::of(2, 0)->ln(15);
        // ln(2) ≈ 0.69314718055994...
        $this->assertTrue($ln2->greaterThan('0.693147180559'));
        $this->assertTrue($ln2->lessThan('0.693147180560'));
    }

    public function testLnNonPositiveThrows(): void
    {
        $this->assertThrows(
            fn() => Decimal::of(0, 0)->ln(),
            \InvalidArgumentException::class
        );
        $this->assertThrows(
            fn() => Decimal::of(-1, 0)->ln(),
            \InvalidArgumentException::class
        );
    }

    public function testPowIntegerExponent(): void
    {
        $result = Decimal::of(2, 0)->pow(10, 0);
        $this->assertSame('1024', (string) $result);
    }

    public function testPowFractionalExponent(): void
    {
        // 2^0.5 = √2
        $result = Decimal::of(2, 0)->pow('0.5', 15);
        $this->assertTrue($result->greaterThan('1.414213562373'));
        $this->assertTrue($result->lessThan('1.414213562374'));
    }

    public function testPowNegativeExponent(): void
    {
        // 2^(-1) = 0.5
        $result = Decimal::of(2, 0)->pow(-1, 10);
        $this->assertSame('0.5000000000', (string) $result);
    }

    public function testPowZeroExponent(): void
    {
        $result = Decimal::of(5, 0)->pow(0, 10);
        $this->assertSame('1.0000000000', (string) $result);
    }

    public function testPowNegativeBaseWithFractionalThrows(): void
    {
        $this->assertThrows(
            fn() => Decimal::of(-2, 0)->pow('0.5', 10),
            \InvalidArgumentException::class
        );
    }

    public function testReciprocal(): void
    {
        $result = Decimal::of(4, 0)->reciprocal(10);
        $this->assertSame('0.2500000000', (string) $result);
    }

    public function testReciprocalOfZeroThrows(): void
    {
        $this->assertThrows(
            fn() => Decimal::of(0, 0)->reciprocal(),
            \DivisionByZeroError::class
        );
    }

    public function testExpLnRoundTrip(): void
    {
        // exp(ln(x)) should equal x
        $x = Decimal::of('5.5', 1);
        $result = $x->ln(15)->exp(10);
        $this->assertTrue($result->greaterThan('5.4999'));
        $this->assertTrue($result->lessThan('5.5001'));
    }

    public function testSqrtSquareRoundTrip(): void
    {
        // (√x)² should equal x
        $x = Decimal::of('7', 0);
        $result = $x->sqrt(15)->pow(2, 10);
        $this->assertTrue($result->greaterThan('6.999999'));
        $this->assertTrue($result->lessThan('7.000001'));
    }
};

exit($test->run());
