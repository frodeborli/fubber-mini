<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via maxItems()
 *
 * @see \mini\Validator\Validator::maxItems()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MaxItems
{
    public function __construct(
        public int $max,
        public ?string $message = null
    ) {}
}
