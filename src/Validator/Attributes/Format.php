<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate string format (email, uri, date-time, etc.)
 *
 * Also used by metadata/schema generation - no need for separate Meta\Format.
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
