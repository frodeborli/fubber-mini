<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Validate via enum()
 *
 * @see \mini\Validator\Validator::enum()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Enum
{
    public function __construct(
        public array $values,
        public ?string $message = null
    ) {}
}
