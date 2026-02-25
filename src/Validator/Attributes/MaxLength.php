<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via maxLength()
 *
 * Query: `validator(User::class)->$prop->maxLength` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::maxLength()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MaxLength
{
    public function __construct(
        public int $max,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
