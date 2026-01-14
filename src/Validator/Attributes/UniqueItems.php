<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via uniqueItems()
 *
 * @see \mini\Validator\Validator::uniqueItems()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class UniqueItems
{
    public function __construct(
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
