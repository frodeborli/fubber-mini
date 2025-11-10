<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via minLength()
 *
 * @see \mini\Validator\Validator::minLength()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MinLength
{
    public function __construct(
        public int $min,
        public ?string $message = null
    ) {}
}
