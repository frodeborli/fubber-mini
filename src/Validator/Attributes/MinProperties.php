<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via minProperties()
 *
 * @see \mini\Validator\Validator::minProperties()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MinProperties
{
    public function __construct(
        public int $min,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
