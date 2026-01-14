<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via exclusiveMinimum()
 *
 * @see \mini\Validator\Validator::exclusiveMinimum()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class ExclusiveMinimum
{
    public function __construct(
        public int|float $min,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
