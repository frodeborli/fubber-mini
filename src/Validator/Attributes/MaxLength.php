<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via maxLength()
 *
 * @see \mini\Validator\Validator::maxLength()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MaxLength
{
    public function __construct(
        public int $max,
        public ?string $message = null
    ) {}
}
