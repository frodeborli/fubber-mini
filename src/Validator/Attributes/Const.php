<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via const()
 *
 * @see \mini\Validator\Validator::const()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Const
{
    public function __construct(
        public mixed $value,
        public ?string $message = null
    ) {}
}
