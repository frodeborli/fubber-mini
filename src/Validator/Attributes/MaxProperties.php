<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via maxProperties()
 *
 * Query: `validator(User::class)->$prop->maxProperties` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::maxProperties()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MaxProperties
{
    public function __construct(
        public int $max,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
