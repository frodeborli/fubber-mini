<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via minimum()
 *
 * @see \mini\Validator\Validator::minimum()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Minimum
{
    public function __construct(
        public int|float $min,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
