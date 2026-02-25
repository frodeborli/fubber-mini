<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via minLength()
 *
 * Query: `validator(User::class)->$prop->minLength` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::minLength()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MinLength
{
    public function __construct(
        public int $min,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
