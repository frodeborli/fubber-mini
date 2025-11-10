<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via maximum()
 *
 * @see \mini\Validator\Validator::maximum()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Maximum
{
    public function __construct(
        public int|float $max,
        public ?string $message = null
    ) {}
}
