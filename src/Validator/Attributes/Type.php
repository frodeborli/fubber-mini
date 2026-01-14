<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via type()
 *
 * @see \mini\Validator\Validator::type()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Type
{
    public function __construct(
        public string|array $type,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
