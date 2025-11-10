<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via pattern()
 *
 * @see \mini\Validator\Validator::pattern()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Pattern
{
    public function __construct(
        public string $pattern,
        public ?string $message = null
    ) {}
}
