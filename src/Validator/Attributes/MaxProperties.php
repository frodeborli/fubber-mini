<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via maxProperties()
 *
 * @see \mini\Validator\Validator::maxProperties()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MaxProperties
{
    public function __construct(
        public int $max,
        public ?string $message = null
    ) {}
}
