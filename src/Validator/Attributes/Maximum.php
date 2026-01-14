<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via maximum()
 *
 * @see \mini\Validator\Validator::maximum()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Maximum
{
    public function __construct(
        public int|float $max,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
