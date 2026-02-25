<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via pattern()
 *
 * Query: `validator(User::class)->$prop->pattern` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::pattern()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Pattern
{
    public function __construct(
        public string $pattern,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
