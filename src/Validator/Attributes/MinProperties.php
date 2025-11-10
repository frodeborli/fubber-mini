<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via minProperties()
 *
 * @see \mini\Validator\Validator::minProperties()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MinProperties
{
    public function __construct(
        public int $min,
        public ?string $message = null
    ) {}
}
