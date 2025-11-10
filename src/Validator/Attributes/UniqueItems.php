<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via uniqueItems()
 *
 * @see \mini\Validator\Validator::uniqueItems()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class UniqueItems
{
    public function __construct(
        public ?string $message = null
    ) {}
}
