<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via multipleOf()
 *
 * @see \mini\Validator\Validator::multipleOf()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MultipleOf
{
    public function __construct(
        public int|float $divisor,
        public ?string $message = null
    ) {}
}
