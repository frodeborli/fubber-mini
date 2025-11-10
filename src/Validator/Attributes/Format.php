<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via format()
 *
 * @see \mini\Validator\Validator::format()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Format
{
    public function __construct(
        public string $format,
        public ?string $message = null
    ) {}
}
