<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via type()
 *
 * @see \mini\Validator\Validator::type()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Type
{
    public function __construct(
        public string|array $type,
        public ?string $message = null
    ) {}
}
