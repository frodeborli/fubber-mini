<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via minItems()
 *
 * @see \mini\Validator\Validator::minItems()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MinItems
{
    public function __construct(
        public int $min,
        public ?string $message = null
    ) {}
}
