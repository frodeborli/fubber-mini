<?php

namespace mini\Util\Math;

/**
 * Arbitrary precision decimal math using integer math with scaling
 *
 * Stores decimals as strings (e.g., "123.456") and internally converts
 * to scaled integers for arithmetic, then converts back.
 *
 * Usage:
 *   DecimalMath::add('10.50', '3.25')     // "13.75"
 *   DecimalMath::multiply('10.5', '2')    // "21"
 *   DecimalMath::divide('10', '3', 4)     // "3.3333"
 */
final class DecimalMath
{
    /**
     * Add two decimals
     */
    public static function add(string $a, string $b): string
    {
        [$aInt, $bInt, $scale] = self::alignScale($a, $b);
        $result = (string) BigInt::of($aInt)->add($bInt);
        return self::insertDecimal($result, $scale);
    }

    /**
     * Subtract b from a
     */
    public static function subtract(string $a, string $b): string
    {
        [$aInt, $bInt, $scale] = self::alignScale($a, $b);
        $result = (string) BigInt::of($aInt)->subtract($bInt);
        return self::insertDecimal($result, $scale);
    }

    /**
     * Multiply two decimals
     */
    public static function multiply(string $a, string $b): string
    {
        [$aInt, $aScale] = self::toScaledInt($a);
        [$bInt, $bScale] = self::toScaledInt($b);

        $result = (string) BigInt::of($aInt)->multiply($bInt);
        return self::insertDecimal($result, $aScale + $bScale);
    }

    /**
     * Divide a by b with specified precision
     *
     * @param int $scale Number of decimal places in result
     */
    public static function divide(string $a, string $b, int $scale = 10): string
    {
        if (self::isZero($b)) {
            throw new \DivisionByZeroError('Division by zero');
        }

        [$aInt, $aScale] = self::toScaledInt($a);
        [$bInt, $bScale] = self::toScaledInt($b);

        // Scale up dividend to get desired precision
        // result_scale = aScale - bScale + extra_scale
        // We want result_scale = $scale, so extra_scale = scale - aScale + bScale
        $extraScale = $scale - $aScale + $bScale;
        if ($extraScale > 0) {
            $aInt = self::padRight($aInt, $extraScale);
        }

        $result = (string) BigInt::of($aInt)->divide($bInt);
        return self::insertDecimal($result, $scale);
    }

    /**
     * Negate: -x
     */
    public static function negate(string $a): string
    {
        if (self::isZero($a)) {
            return '0';
        }
        return str_starts_with($a, '-') ? substr($a, 1) : '-' . $a;
    }

    /**
     * Absolute value
     */
    public static function absolute(string $a): string
    {
        return ltrim($a, '-');
    }

    /**
     * Modulus (remainder after integer division)
     */
    public static function modulus(string $a, string $b): string
    {
        if (self::isZero($b)) {
            throw new \DivisionByZeroError('Modulus by zero');
        }

        [$aInt, $bInt, $scale] = self::alignScale($a, $b);
        $result = (string) BigInt::of($aInt)->modulus($bInt);
        return self::insertDecimal($result, $scale);
    }

    /**
     * Compare two decimals: -1 if a < b, 0 if equal, 1 if a > b
     */
    public static function compare(string $a, string $b): int
    {
        [$aInt, $bInt, $_] = self::alignScale($a, $b);
        return BigInt::of($aInt)->compare($bInt);
    }

    /**
     * Check if value is zero
     */
    public static function isZero(string $value): bool
    {
        $clean = ltrim($value, '-+');
        $clean = ltrim($clean, '0');
        $clean = trim($clean, '.');
        $clean = rtrim($clean, '0');
        return $clean === '';
    }

    /**
     * Round a decimal to specified scale
     */
    public static function round(string $value, int $scale, int $mode = PHP_ROUND_HALF_UP): string
    {
        [$intPart, $currentScale] = self::toScaledInt($value);

        if ($currentScale <= $scale) {
            // No rounding needed, just pad with zeros
            return self::insertDecimal($intPart . str_repeat('0', $scale - $currentScale), $scale);
        }

        // Need to round
        $dropDigits = $currentScale - $scale;
        $divisor = str_pad('1', $dropDigits + 1, '0');

        // Get the quotient and remainder
        $intVal = BigInt::of($intPart);
        $quotient = (string) $intVal->divide($divisor);
        $remainder = (string) $intVal->modulus($divisor);

        // Check if we need to round up
        $halfDivisor = str_pad('5', $dropDigits, '0');
        $absRemainder = BigInt::of($remainder)->absolute();
        $cmp = $absRemainder->compare($halfDivisor);

        $negative = str_starts_with($intPart, '-');
        $shouldRoundUp = match ($mode) {
            PHP_ROUND_HALF_UP => $cmp >= 0,
            PHP_ROUND_HALF_DOWN => $cmp > 0,
            PHP_ROUND_HALF_EVEN => $cmp > 0 || ($cmp === 0 && ((int) substr($quotient, -1) % 2 === 1)),
            PHP_ROUND_HALF_ODD => $cmp > 0 || ($cmp === 0 && ((int) substr($quotient, -1) % 2 === 0)),
            default => $cmp >= 0,
        };

        if ($shouldRoundUp && !self::isZero($remainder)) {
            $quotient = $negative
                ? (string) BigInt::of($quotient)->subtract(1)
                : (string) BigInt::of($quotient)->add(1);
        }

        return self::insertDecimal($quotient, $scale);
    }

    /**
     * Convert decimal string to scaled integer and its scale
     *
     * "123.456" -> ["123456", 3]
     * "-10.5" -> ["-105", 1]
     * "42" -> ["42", 0]
     */
    private static function toScaledInt(string $value): array
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-+');

        $pos = strpos($value, '.');
        if ($pos === false) {
            $intPart = ltrim($value, '0') ?: '0';
            return [$negative && $intPart !== '0' ? '-' . $intPart : $intPart, 0];
        }

        $intPart = substr($value, 0, $pos);
        $decPart = substr($value, $pos + 1);
        $scale = strlen($decPart);

        // Combine integer and decimal parts
        $combined = ltrim($intPart . $decPart, '0') ?: '0';

        return [$negative && $combined !== '0' ? '-' . $combined : $combined, $scale];
    }

    /**
     * Align two decimals to the same scale, returning their scaled integer forms
     *
     * Returns [aScaledInt, bScaledInt, commonScale]
     */
    private static function alignScale(string $a, string $b): array
    {
        [$aInt, $aScale] = self::toScaledInt($a);
        [$bInt, $bScale] = self::toScaledInt($b);

        $maxScale = max($aScale, $bScale);

        // Pad with zeros to align scales
        if ($aScale < $maxScale) {
            $aInt = self::padRight($aInt, $maxScale - $aScale);
        }
        if ($bScale < $maxScale) {
            $bInt = self::padRight($bInt, $maxScale - $bScale);
        }

        return [$aInt, $bInt, $maxScale];
    }

    /**
     * Pad integer string with zeros on the right (handles negatives)
     */
    private static function padRight(string $value, int $zeros): string
    {
        if ($zeros <= 0) {
            return $value;
        }
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        $padded = $abs . str_repeat('0', $zeros);
        return $negative ? '-' . $padded : $padded;
    }

    /**
     * Insert decimal point into scaled integer
     *
     * insertDecimal("123456", 3) -> "123.456"
     * insertDecimal("5", 2) -> "0.05"
     * insertDecimal("-100", 1) -> "-10"
     */
    private static function insertDecimal(string $value, int $scale): string
    {
        if ($scale <= 0) {
            return $value;
        }

        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');

        // Pad with leading zeros if needed
        if (strlen($abs) <= $scale) {
            $abs = str_pad($abs, $scale + 1, '0', STR_PAD_LEFT);
        }

        $intPart = substr($abs, 0, -$scale);
        $decPart = substr($abs, -$scale);

        // Normalize: remove trailing zeros and unnecessary decimal point
        $decPart = rtrim($decPart, '0');
        if ($decPart === '') {
            $result = $intPart;
        } else {
            $result = $intPart . '.' . $decPart;
        }

        return $negative && $result !== '0' ? '-' . $result : $result;
    }
}
