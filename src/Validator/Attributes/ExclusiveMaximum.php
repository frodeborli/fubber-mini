<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via exclusiveMaximum()
 *
 * @see \mini\Validator\Validator::exclusiveMaximum()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class ExclusiveMaximum
{
    public function __construct(
        public int|float $max,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
