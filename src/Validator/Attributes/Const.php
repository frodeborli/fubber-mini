<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via const()
 *
 * @see \mini\Validator\Validator::const()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Const
{
    public function __construct(
        public mixed $value,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
