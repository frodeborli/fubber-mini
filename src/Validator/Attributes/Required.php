<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via required()
 *
 * @see \mini\Validator\Validator::required()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Required
{
    public function __construct(
        public ?string $message = null
    ) {}
}
