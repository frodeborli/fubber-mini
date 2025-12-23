<?php

namespace mini\Util\Math;

use Stringable;

/**
 * Common read interface for arbitrary precision numbers
 *
 * Implemented by BigInt, Decimal, and Expr.
 * This is the minimal interface for external consumers that need to read values.
 *
 * Arithmetic operations are NOT part of this interface because each type
 * has different acceptable operand types:
 * - BigInt accepts: BigInt|int|string
 * - Decimal accepts: BigInt|int|float|string
 * - Expr accepts: BigInt|Decimal|Expr|int|float|string
 */
interface NumberInterface extends Stringable
{
    /**
     * Number of decimal places
     *
     * BigInt always returns 0.
     * Decimal returns its configured scale.
     * Expr returns the scale of the evaluated result (or null if unevaluated).
     */
    public function scale(): ?int;

    /**
     * Convert to string representation
     */
    public function __toString(): string;
}
