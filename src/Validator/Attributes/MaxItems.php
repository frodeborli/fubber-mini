<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via maxItems()
 *
 * Query: `validator(User::class)->$prop->maxItems` — never use ReflectionClass directly.
 *
 * @see \mini\Validator\Validator::maxItems()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MaxItems
{
    public function __construct(
        public int $max,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
