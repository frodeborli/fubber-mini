<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via exclusiveMaximum()
 *
 * @see \mini\Validator\Validator::exclusiveMaximum()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ExclusiveMaximum
{
    public function __construct(
        public int|float $max,
        public ?string $message = null
    ) {}
}
