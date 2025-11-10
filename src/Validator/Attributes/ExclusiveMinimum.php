<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via exclusiveMinimum()
 *
 * @see \mini\Validator\Validator::exclusiveMinimum()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ExclusiveMinimum
{
    public function __construct(
        public int|float $min,
        public ?string $message = null
    ) {}
}
