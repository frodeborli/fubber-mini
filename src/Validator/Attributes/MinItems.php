<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via minItems()
 *
 * @see \mini\Validator\Validator::minItems()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MinItems
{
    public function __construct(
        public int $min,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
