<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via multipleOf()
 *
 * @see \mini\Validator\Validator::multipleOf()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MultipleOf
{
    public function __construct(
        public int|float $divisor,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
