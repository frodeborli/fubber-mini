<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via enum()
 *
 * Query: `validator(User::class)->$prop->enum` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::enum()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Enum
{
    public function __construct(
        public array $values,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
