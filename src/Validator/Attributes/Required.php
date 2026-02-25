<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via required()
 *
 * Query: `validator(User::class)->$prop->required` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::required()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Required
{
    public function __construct(
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
