<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate via format()
 *
 * @see \mini\Validator\Validator::format()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Format
{
    public function __construct(
        public string $format,
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
