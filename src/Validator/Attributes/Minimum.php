<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via minimum()
 *
 * @see \mini\Validator\Validator::minimum()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Minimum
{
    public function __construct(
        public int|float $min,
        public ?string $message = null
    ) {}
}
